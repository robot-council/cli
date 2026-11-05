<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Sleep;
use JsonException;

/**
 * What a harness hands its stop hook on stdin, read for the one thing `pending` needs from it (#356).
 *
 * **The folder of the chat that ended its turn.** A Cursor hook runs in `~/.cursor`, so neither its
 * working directory nor its environment is guaranteed to name the repository; its stdin JSON
 * carries `workspace_roots`. The hook script reads that payload itself to pick the answer's shape
 * and hands it on, so a script that does not still works: `pending` then finds stdin empty and
 * falls back to `CURSOR_PROJECT_DIR`.
 *
 * **Bounded, and tolerant.** Nothing here may keep a hook waiting or make it fail: no stream, a
 * terminal, a read that stalls, a payload that is not JSON, and a BOM before the `{` (measured on
 * robot-council/cli#72) all end in null, and the caller carries on without a folder.
 */
final class HookPayload
{
    /**
     * The most of a payload to read, since only `workspace_roots` near its start is wanted.
     */
    private const int MAXIMUM_BYTES = 1_048_576;

    /**
     * How long to wait for a payload on a stream that can be polled.
     */
    private const float WAIT_SECONDS = 1.0;

    /**
     * @param  resource|null  $stream  Where the payload arrives; standard input by default.
     */
    public function __construct(private $stream = null) {}

    /**
     * The first workspace folder the payload names, or null.
     */
    public function workspaceRoot(): ?string
    {
        return self::rootIn($this->contents());
    }

    /**
     * The first workspace folder in a payload, in the spelling this machine uses.
     */
    public static function rootIn(string $payload): ?string
    {
        $payload = ltrim($payload, "\xEF\xBB\xBF \t\r\n");

        if ($payload === '') {
            return null;
        }

        try {
            $decoded = json_decode($payload, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        $roots = \is_array($decoded) ? ($decoded['workspace_roots'] ?? null) : null;

        if (! \is_array($roots)) {
            return null;
        }

        foreach ($roots as $root) {
            if (\is_string($root) && trim($root) !== '') {
                return self::local($root);
            }
        }

        return null;
    }

    /**
     * A folder as this machine writes it: Cursor spells a Windows drive as `/c:/Users/...`.
     */
    private static function local(string $root): string
    {
        if (PHP_OS_FAMILY === 'Windows' && preg_match('#^/[A-Za-z]:[/\\\\]#', $root) === 1) {
            return substr($root, 1);
        }

        return $root;
    }

    /**
     * Whatever is waiting on the stream, without waiting long for it.
     */
    private function contents(): string
    {
        $stream = $this->stream ?? (\defined('STDIN') ? STDIN : null);

        if (! \is_resource($stream) || @stream_isatty($stream)) {
            return '';
        }

        $deadline = microtime(true) + self::WAIT_SECONDS;
        $read = '';

        // A pipe that cannot be switched to non-blocking (Windows) is read to its end, which is
        // immediate for a hook script that already consumed it, and for one that forwards the payload
        $polled = @stream_set_blocking($stream, false);

        while (\strlen($read) < self::MAXIMUM_BYTES) {
            $chunk = @fread($stream, 65536);

            if (\is_string($chunk) && $chunk !== '') {
                $read .= $chunk;

                continue;
            }

            if (feof($stream) || ! $polled || microtime(true) >= $deadline) {
                break;
            }

            Sleep::usleep(10_000);
        }

        return $read;
    }
}
