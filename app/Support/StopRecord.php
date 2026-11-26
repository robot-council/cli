<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * One line per stopped bridge, in a file that outlives it (#396).
 *
 * **Why a file.** Measured on josh-home on 2026-11-26 (#362): 42 bridges stopped with their parents
 * alive, the service recorded each as `ended`, and nothing on the machine said which end of the loop
 * ran. Claude Code's per-session MCP log has no row for a stop, and a bridge's stderr is kept only
 * where a harness chooses to keep it.
 *
 * **What a line holds, and what it must not.** A time, the pids, the session id, the bridge version
 * and a reason from a closed list, plus a few short facts. Never an exception's message, a URL or a
 * header: a message can carry a token, and the class name and a status separate the paths. Every
 * fact is held to a short, plain alphabet below so a caller cannot pass one through by accident.
 *
 * **Appended under an exclusive lock and bounded**, so forty bridges stopping together neither
 * interleave nor grow the file without limit. **A failure to write is returned, never thrown**: this
 * runs while the bridge is already ending, and replacing a clean stop with a worse failure helps nobody.
 */
final readonly class StopRecord
{
    /**
     * The file's name, beside the sink directory.
     */
    public const string FILE = 'stops.jsonl';

    /**
     * How many lines the file keeps; the newest survive.
     */
    public const int KEEP = 1000;

    /**
     * The facts a line may carry. Anything else is dropped rather than written.
     */
    public const array FACTS = ['parent', 'session', 'version', 'reader', 'status', 'signal', 'exception'];

    /**
     * How long a fact may be.
     */
    private const int FACT_LENGTH = 120;

    /**
     * How many times to try for the lock, and how long between tries.
     */
    private const int LOCK_TRIES = 40;

    private const int LOCK_WAIT_MILLISECONDS = 50;

    public function __construct(private string $path) {}

    /**
     * The record at its place in this machine's state directory.
     */
    public static function atDefaultPath(): self
    {
        return new self(PendingEvents::stateDirectory().'/'.self::FILE);
    }

    /**
     * Where the file is.
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * Add one line.
     *
     * @param  StopReason  $reason  Which end of the loop ran.
     * @param  array<string, int|string|null>  $facts  Short facts, limited to `FACTS`; null ones are left out.
     * @return string|null Why nothing was written, or null when the line is in the file.
     */
    public function append(StopReason $reason, array $facts = []): ?string
    {
        $line = $this->line($reason, $facts);

        try {
            $directory = \dirname($this->path);

            if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
                return \sprintf('Could not create `%s` to record why this bridge stopped.', $directory);
            }

            $handle = @fopen($this->path, 'c+');

            if ($handle === false) {
                return \sprintf('Could not open `%s` to record why this bridge stopped.', $this->path);
            }

            try {
                if (! $this->lock($handle)) {
                    return \sprintf('Could not lock `%s` to record why this bridge stopped.', $this->path);
                }

                $lines = $this->kept($handle);
                $lines[] = $line;

                rewind($handle);
                ftruncate($handle, 0);
                fwrite($handle, implode("\n", $lines)."\n");
                fflush($handle);
                flock($handle, LOCK_UN);
            } finally {
                fclose($handle);
            }
        } catch (Throwable $throwable) {
            return \sprintf('Could not record why this bridge stopped (%s).', $throwable::class);
        }

        return null;
    }

    /**
     * The line, as JSON, with only what is allowed in it.
     *
     * @param  array<string, int|string|null>  $facts
     */
    private function line(StopReason $reason, array $facts): string
    {
        $line = [
            'at' => new DateTimeImmutable('now', new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z'),
            'pid' => getmypid(),
            'reason' => $reason->value,
        ];

        foreach (self::FACTS as $name) {
            $value = $facts[$name] ?? null;

            if ($value === null) {
                continue;
            }

            $line[$name] = \is_int($value) ? $value : $this->plain($value);
        }

        return json_encode($line, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /**
     * A fact reduced to letters, digits and a few separators, and cut short.
     */
    private function plain(string $value): string
    {
        $plain = preg_replace('/[^A-Za-z0-9_.:\\\\-]/', '', $value) ?? '';

        return substr($plain, 0, self::FACT_LENGTH);
    }

    /**
     * Take the exclusive lock, trying for about two seconds.
     *
     * @param  resource  $handle
     */
    private function lock($handle): bool
    {
        for ($try = 0; $try < self::LOCK_TRIES; $try++) {
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                return true;
            }

            Sleep::for(self::LOCK_WAIT_MILLISECONDS)->milliseconds();
        }

        return false;
    }

    /**
     * The lines already there, newest last, leaving room for one more.
     *
     * @param  resource  $handle
     * @return list<string>
     */
    private function kept($handle): array
    {
        rewind($handle);

        $contents = stream_get_contents($handle);
        $lines = $contents === false || $contents === ''
            ? []
            : array_values(array_filter(explode("\n", $contents), static fn (string $line): bool => trim($line) !== ''));

        return \array_slice($lines, -(self::KEEP - 1));
    }
}
