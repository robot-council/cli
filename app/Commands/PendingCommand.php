<?php

declare(strict_types=1);

namespace App\Commands;

use App\Support\MachineIdentity;
use App\Support\PendingEvents;
use App\Support\Stderr;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use LaravelZero\Framework\Commands\Command;

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
#[Description('Print the fleet events waiting for this harness, and clear them')]
#[Signature('pending
    {--service= : The service base URL, defaulting to ROBOT_COUNCIL_SERVICE}
    {--project= : The repository or workspace the bridge was started for}
    {--harness= : Which enrolled harness this is, when detection cannot tell}
    {--peek : Show what is waiting without clearing it}')]
final class PendingCommand extends Command
{
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

        $events = $peeking ? $pending->peek() : $pending->drain();

        // A drain is a stop hook at a turn end; a peek is somebody looking, which is not a turn.
        // Claude Code only, the one harness whose bridge can keep a cache warm, so no other
        // harness's hook starts writing a file nothing reads
        if (! $peeking && $harness === 'claude') {
            $pending->markTurnEnded();
        }

        foreach ($events as $event) {
            $this->line($this->describe($event));
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
