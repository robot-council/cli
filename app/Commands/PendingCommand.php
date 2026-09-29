<?php

declare(strict_types=1);

namespace App\Commands;

use App\Support\MachineIdentity;
use App\Support\PendingEvents;
use App\Support\Stderr;
use App\Support\UnreadableSink;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Output\StreamOutput;

/**
 * Prints the fleet events waiting for this harness, and clears them.
 *
 * **A harness's stop hook calls this, and that is the whole reason it exists as a command.** The
 * bridge is long-running and holds the session and the feed cursor; a stop hook is a short-lived
 * process the harness spawns at a turn boundary and which knows none of that. This is the one line
 * a hook needs, so a hook does not have to know where the sink is or what shape it has (cli#60).
 *
 * It prints to stdout on purpose, unlike `mcp`: nothing parses this as a protocol stream, and what
 * a hook does with it is feed it back to the agent.
 *
 * **Empty is success and prints nothing.** A hook that injects whatever this writes must end the
 * turn normally when the fleet has been quiet, and a command that printed "nothing waiting" would
 * make every turn continue forever.
 */
#[Description('Print the fleet events waiting for this harness, and clear them at the next turn end')]
#[Signature('pending
    {--service= : The service base URL, defaulting to ROBOT_COUNCIL_SERVICE}
    {--project= : The repository or workspace the bridge was started for}
    {--harness= : Which enrolled harness this is, when detection cannot tell}
    {--peek : Show what is waiting without clearing it}
    {--format= : Print the whole stop-hook answer, as block (Claude Code, Codex) or cursor}')]
final class PendingCommand extends Command
{
    /**
     * What a stop hook's answer opens with, before the events.
     */
    public const string NEWS = 'Robot Council has news:';

    /**
     * The exit code for a sink that could not be read, apart from every other failure (cli#345).
     *
     * **Its own code, so the stop hook can tell it apart.** The hook fails open on a misconfigured
     * machine -- no `robot-council` on its `PATH`, no service named -- and must go on doing so, or
     * every turn on such a seat would continue once to say so. A sink that exists and could not be
     * read is different: its events are waiting, and the agent is told.
     */
    public const int UNREADABLE = 3;

    /**
     * Write to stdout, and say whether every byte arrived.
     */
    private function written(string $text): bool
    {
        $output = $this->output->getOutput();

        // An output held in memory, as a test or a caller capturing it uses, has no write to fail
        if (! $output instanceof StreamOutput) {
            $this->output->write($text);

            return true;
        }

        $stream = $output->getStream();

        return @fwrite($stream, $text) === \strlen($text) && fflush($stream);
    }

    /**
     * Drain the sink.
     *
     * @return int The exit code.
     */
    public function handle(): int
    {
        $service = $this->resolveService();

        if ($service === null) {
            $this->diagnostic('Pass --service, or set ROBOT_COUNCIL_SERVICE, with the URL of your fleet.');

            return self::FAILURE;
        }

        // What identifies a bridge, resolved the same way, because the sink is keyed by it. A hook
        // that passes what its harness configuration already carries, run in the same checkout,
        // finds the sink the bridge beside it is writing: with no project named, the checkout is
        // resolved from this process's working directory, as the bridge resolves it from its own
        // (#299).
        $harness = MachineIdentity::resolveHarness($this->stringOption('harness'));

        if ($harness === null) {
            $this->diagnostic(
                'Could not tell which harness this is. Pass --harness=<name>, or set '
                ."ROBOT_COUNCIL_HARNESS in this harness's configuration."
            );

            return self::FAILURE;
        }

        $pending = new PendingEvents($service, $harness, $this->stringOption('project'), $this->projectDirectory());

        $peeking = $this->option('peek') === true;
        $format = $this->stringOption('format');

        // Refused before the sink is touched, so a hook passing a shape this version does not know
        // leaves every event where it was
        if ($format !== null && ! \in_array($format, ['block', 'cursor'], true)) {
            $this->diagnostic('--format takes block or cursor.');

            return self::FAILURE;
        }

        // **Delivered at least once** (cli#341): what an earlier call printed is removed now, and
        // what this call prints is only marked, below, once it has been written
        //
        // **A sink it could not read is an error, never an empty answer** (cli#345). Empty output
        // tells a hook and an agent the fleet has been quiet, so a failed read says why on stderr
        // and exits non-zero, having marked and removed nothing
        try {
            $events = $peeking ? $pending->inspect() : $pending->deliver();
        } catch (UnreadableSink $unreadableSink) {
            $this->diagnostic($unreadableSink->getMessage());

            return self::UNREADABLE;
        }

        // A delivery is a stop hook at a turn end; a peek is somebody looking, which is not a turn.
        // Claude Code only, the one harness whose bridge can keep a cache warm, so no other
        // harness's hook starts writing a file nothing reads
        if (! $peeking && $harness === 'claude') {
            $pending->markTurnEnded();
        }

        if ($events === []) {
            return self::SUCCESS;
        }

        $lines = implode("\n", array_map($this->describe(...), $events));

        $text = match ($format) {
            // The hook's whole answer, so the script hands it straight to the harness and no
            // second process stands between this write and the harness reading it (cli#341)
            'block' => json_encode(['decision' => 'block', 'reason' => self::NEWS."\n".$lines]),
            'cursor' => json_encode(['followup_message' => self::NEWS."\n".$lines]),
            default => $lines."\n",
        };

        // **Marked only once every byte is written.** Symfony's own writer drops a failed write
        // without a word, so a stdout whose reader has gone -- a hook the harness killed while this
        // process ran on -- would otherwise be marked delivered into nothing (cli#341)
        if ($this->written((string) $text) && ! $peeking) {
            $pending->markDelivered($events);
        }

        return self::SUCCESS;
    }

    /**
     * One event as a line an agent can read.
     *
     * Rendered rather than dumped as JSON, because what reads this is a language model being handed
     * a turn's worth of context and not a parser. The provenance the server derived is kept, since
     * #14's threat model is that event content is untrusted input to something with shell access:
     * who said it is what makes it weighable.
     *
     * **A `bridge.` entry carries no attribution, and leaving it off is not cosmetic.** Every other
     * entry came from the feed and is somebody's words, so who said it is what makes it weighable.
     * An entry this side wrote came from nobody, and the fallback for a missing actor is `from an
     * unnamed session` -- which would tell a reader the fleet delivered something it structurally
     * cannot (#175), through the one door the `bridge.` prefix was chosen to close.
     *
     * @param  array<array-key, mixed>  $event  One entry from the sink.
     */
    private function describe(array $event): string
    {
        $type = \is_string($event['type'] ?? null) ? $event['type'] : 'event';
        $body = \is_string($event['body'] ?? null) ? $event['body'] : '';
        $when = \is_string($event['created_at'] ?? null) ? $event['created_at'] : '';

        return trim(sprintf(
            '[%s] %s%s%s',
            $type,
            $body,
            str_starts_with($type, PendingEvents::LOCAL_PREFIX) ? '' : ' from '.$this->said($event),
            $when === '' ? '' : ' at '.$when
        ));
    }

    /**
     * Who the fleet says an event came from.
     *
     * @param  array<array-key, mixed>  $event  One event from the feed.
     */
    private function said(array $event): string
    {
        $actor = $event['actor'] ?? null;

        return \is_array($actor) && \is_string($actor['github_login'] ?? null)
            ? $actor['github_login']
            : 'an unnamed session';
    }

    /**
     * The directory the harness's session was launched in, when the harness says.
     *
     * **Not this process's working directory, which moves.** Claude Code starts a hook in the
     * session's current directory, and an agent's `cd` into an added directory moves it there,
     * while the bridge stays where it was launched. Measured on Claude Code 2.1.282: after `cd` into
     * an `--add-dir` repository the `Stop` hook ran in that repository, with `CLAUDE_PROJECT_DIR`
     * still naming the launch directory, which is also where the MCP server ran. So the hook
     * resolves its checkout from `CLAUDE_PROJECT_DIR` and would otherwise drain another checkout's
     * sink (#299). Other harnesses set no such variable, and fall back to the working directory.
     */
    private function projectDirectory(): ?string
    {
        $directory = getenv('CLAUDE_PROJECT_DIR');

        return \is_string($directory) && $directory !== '' ? $directory : null;
    }

    /**
     * The service this harness is enrolled against.
     */
    private function resolveService(): ?string
    {
        $given = $this->stringOption('service');

        if ($given === null) {
            $fromEnvironment = getenv('ROBOT_COUNCIL_SERVICE');

            $given = \is_string($fromEnvironment) && $fromEnvironment !== '' ? $fromEnvironment : null;
        }

        return $given === null ? null : rtrim($given, '/');
    }

    /**
     * One option as a non-empty string, or null.
     */
    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return \is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Say something that is not a fleet event.
     *
     * To stderr, so a hook that injects this command's stdout injects events and nothing else.
     */
    private function diagnostic(string $message): void
    {
        Stderr::say($this->output->getOutput(), $message);
    }
}
