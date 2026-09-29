<?php

declare(strict_types=1);

namespace App\Commands;

use App\Support\Bridge;
use App\Support\Credentials\Credentials;
use App\Support\FleetJoin;
use App\Support\JoinRecord;
use App\Support\KeepWarm;
use App\Support\MachineIdentity;
use App\Support\ParentProcess;
use App\Support\PendingEvents;
use App\Support\Session;
use App\Support\Stderr;
use App\Support\StdinReader;
use App\Support\StopHooks;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Sleep;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;
use Throwable;

/**
 * The stdio MCP bridge a harness launches as a child process.
 *
 * One session per process. The bridge holds the credential, so no token enters a harness's
 * configuration or an agent's transcript -- which is the whole reason this exists rather than the
 * harness talking to the service directly.
 *
 * **stdout carries protocol messages and nothing else.** A harness parses that stream. A stray
 * line is not a visible error but a malformed message, and the failure looks like a bridge that
 * works except when it does not. Diagnostics go to stderr.
 */
#[Description('Serve the coordination tools over stdio, for a harness to launch')]
#[Signature('mcp
    {--service= : The service base URL, defaulting to ROBOT_COUNCIL_SERVICE}
    {--project= : The repository or workspace this session belongs to}
    {--repository= : The GitHub repository this session works in, as owner/name; read from the checkout when omitted}
    {--work-location= : Which working copy of that repository this is; read from the checkout when omitted}
    {--harness= : Which enrolled harness this process is, when detection cannot tell}
    {--auto-join : Join the fleet at launch, for a checkout that should join every time it starts}
    {--role= : With --auto-join, the role to ask for: build, ci or coordinator}
    {--capacity= : With --auto-join, how many tasks this session will hold at once, up to its seat cap}
    {--keep-warm= : Claude Code only: wake the session after this many idle minutes, to keep its prompt cache warm}
    {--keep-warm-for= : With --keep-warm, stop once the session has been idle this many minutes}')]
final class McpCommand extends Command
{
    /**
     * What the agent reads at connect when a restarted Cursor bridge has rejoined by itself (#333).
     */
    public const string REJOINED_NOTICE = 'This Cursor application was on the Robot Council fleet before Cursor '
        .'restarted its bridge, and the bridge has rejoined it as it was, with the repository, work location, role '
        .'and capacity of the earlier join. It is a new fleet session: tasks and locks the earlier one held were released. '
        .'Tell your operator on your next turn.';

    /**
     * How long a rejoin waits for the bridge it replaces to let go of the seat (#333).
     *
     * Measured on Cursor 3.17.19: the old bridge was stopped 58ms before the new one was spawned,
     * and ending a session is one request. Two seconds is ample and still short beside a reload.
     */
    public const int REJOIN_SEAT_WAIT_MILLISECONDS = 2000;

    /**
     * How long after a join an application that has not rejoined is still told about it (#333).
     */
    public const int DROPPED_NOTICE_SECONDS = 86400;

    /**
     * Run the bridge until stdin closes or a signal arrives.
     *
     * **No session until somebody joins (cli#127).** The bridge comes up, answers the handshake,
     * and offers one tool; the credential, the session, the fleet-cannot-deliver diagnostic and the
     * sink all wait for `join`, or for `--auto-join`. A machine with no credential therefore comes
     * up too, and `join` says what is missing in a result the agent can relay -- where it used to
     * die at launch with a line on stderr most harnesses bury.
     *
     * @param  Factory  $http  The HTTP client.
     * @param  Credentials  $credentials  Where the installation credential lives.
     * @return int The exit code.
     */
    public function handle(Factory $http, Credentials $credentials, ParentProcess $parent): int
    {
        $service = $this->resolveService();

        if ($service === null) {
            $this->diagnostic('Pass --service, or set ROBOT_COUNCIL_SERVICE, with the URL of your fleet.');

            return self::FAILURE;
        }

        // The same cascade `InstallationChoice` uses to pick the credential, so the sink is keyed by
        // the harness this process actually is rather than by a second opinion.
        $harness = MachineIdentity::resolveHarness($this->stringOption('harness')) ?? 'unknown-harness';

        $join = new FleetJoin(
            $http,
            $credentials,
            $service,
            $harness,
            $this->stringOption('harness'),
            $this->stringOption('project'),
            $this->stringOption('repository'),
            $this->stringOption('work-location'),
            $this->diagnostic(...),
        );

        // **The bridge reads a socket, not stdin.** A child does the blocking read, because
        // `stream_select()` does not honor its timeout on a Windows pipe and the loop would then
        // only turn over when the harness sends a frame -- so an idle agent would get no heartbeat,
        // no renewal and no directives, which is the agent all three exist for (#131).
        try {
            $reader = new StdinReader;
        } catch (RuntimeException $runtimeException) {
            $this->diagnostic($runtimeException->getMessage());

            return self::FAILURE;
        }

        // **Held for the life of this process**, which is what makes it a seat: the lock goes when
        // the process does, however it goes (#320). **Taken after the reader starts**, so the
        // long-lived child never holds a copy of the lock: a child that inherited it would keep
        // the seat held after this process was killed, for as long as the harness kept stdin open.
        // Close-on-exec covers this on POSIX (`SeatTest`); the order is what covers Windows
        $seat = new PendingEvents($service, $harness, $this->stringOption('project'), useRecordedKey: true);

        // **Resolved once, here, and recorded for everything else to use** (cli#346): the seat, the
        // follower, the keep-alive and the stop hook then key one sink, whatever git says later
        $recorded = $seat->recordKey();

        if ($recorded !== null) {
            $this->diagnostic($recorded);
        }

        // **Cursor only, and only when named** (#333). Cursor restarts its MCP servers by itself and
        // each stop ends the session, so a Cursor seat needs a record to rejoin from. No other
        // harness restarts the bridge under a live session, and none gets one: joining stays the
        // agent's act there. Named rather than detected, because the detector reads `CURSOR_AGENT`
        // before `CLAUDECODE`, so Claude Code started from a Cursor terminal detects as `cursor`;
        // the Cursor wiring names it (`ROBOT_COUNCIL_HARNESS=cursor` in `mcp.json`).
        $record = MachineIdentity::namedHarness($this->stringOption('harness')) === 'cursor'
            ? new JoinRecord($seat->joinRecordPath(), $parent->identity())
            : null;

        // Not with `--auto-join`, which joins anyway and with what its own options say
        $recalled = $this->option('auto-join') === true ? null : $record?->recall();
        $sameParent = $record instanceof JoinRecord && $recalled !== null && $record->sameParent($recalled['parent']);

        // **A reload overlaps the bridge it replaces**, which is still ending its session and
        // clearing the sink when this one starts. Rejoining before it has gone would warn of a
        // second bridge that is on its way out, and let its clearing take this session's first
        // events. So a rejoin waits for the seat, briefly; a seat still held after that is a bridge
        // that is not leaving, and nothing rejoins beside it.
        $sharedSink = $this->sharedSinkWarning($seat, $sameParent ? self::REJOIN_SEAT_WAIT_MILLISECONDS : 0);

        $bridge = new Bridge(
            null,
            $service,
            Bridge::HEARTBEAT_SECONDS,

            // **Claude Code only**, the one harness that reads `claude/channel`. A session started
            // without `--channels` drops the notices, and the stop hook delivers as before (cli#62).
            channel: $harness === 'claude',
            join: $join(...),
            keepWarm: $this->keepWarm($service, $harness),
            stopHook: $this->stopHookDelivers($harness),
            sharedSink: $sharedSink,
            joinRecord: $record,
        );

        $rejoin = null;

        if ($record instanceof JoinRecord && $recalled !== null) {
            if ($sameParent && $sharedSink === null) {
                $rejoin = $recalled['arguments'];
            } elseif (time() - $recalled['joined_at'] > self::DROPPED_NOTICE_SECONDS) {
                // Old news: an operator who quit Cursor to stay off the fleet is not told on every launch
                $record->forget();
            } else {
                // A quit and relaunch, or another Cursor: tell, and never join (#321's option A)
                $this->tellAtConnect($bridge, $this->droppedNotice($recalled['joined_at']));
            }
        }

        if ($this->stringOption('role') !== null && $this->option('auto-join') !== true) {
            // A role is asked for at the join, and without `--auto-join` this process does not join
            $this->diagnostic('--role applies only with --auto-join; pass the role to the `join` tool instead.');
        }

        if ($this->input->hasParameterOption('--capacity') && $this->option('auto-join') !== true) {
            $this->diagnostic('--capacity applies only with --auto-join; pass the capacity to the `join` tool instead.');
        }

        // **Refused before any request** (#302), and the automatic join with it, since joining
        // with a different capacity than asked is worse than not joining: `join` still works.
        // Read only for an automatic join, the one thing it applies to
        $capacity = $this->option('auto-join') === true ? $this->capacityOption() : null;

        $this->listenForSignals($bridge);

        try {
            // After the signal handlers, so a `SIGTERM` arriving during the join's network calls
            // is caught and the session it started is still ended below
            if ($this->option('auto-join') === true && $capacity !== false) {
                $bridge->joinNow([
                    'role' => $this->stringOption('role'),
                    'repository' => null,
                    'work_location' => null,
                    'capacity' => $capacity,
                ], $this->diagnostic(...));
            } elseif ($rejoin !== null) {
                $this->diagnostic('rejoining the fleet: this Cursor application joined before its bridge was restarted.');

                $bridge->joinNow($rejoin, $this->diagnostic(...));

                $this->tellAtConnect($bridge, $bridge->session() instanceof Session
                    ? self::REJOINED_NOTICE
                    : $this->droppedNotice($recalled['joined_at'] ?? time()));
            }

            $bridge->run($reader->stream(), STDOUT, $this->diagnostic(...));
        } catch (Throwable $throwable) {
            // **Nothing may escape to the renderer.** `Illuminate\Console\Application` sets
            // `setCatchExceptions(false)`, so an uncaught throwable reaches the kernel's own
            // handler, which renders it through Collision to plain STDOUT -- measured at ~915 bytes
            // of formatted exception written straight into the protocol stream. A harness reads
            // that as a malformed message.
            $this->diagnostic($throwable instanceof RuntimeException
                ? $throwable->getMessage()
                : 'The bridge stopped unexpectedly.');

            return self::FAILURE;
        } finally {
            // Before the session ends, because the child holds the harness's stdin and would
            // otherwise outlive the bridge that started it.
            $reader->stop();

            // The session must not outlive the harness. Ending it marks it gone now, rather than
            // leaving it to the presence sweep's stale and gone thresholds, and core's next sweep
            // releases the tasks and locks it held. A bridge that never joined has nothing to end,
            // and leaves nothing on the fleet.
            $bridge->session()?->end();

            // And the fleet events go with it. Unread ones name tasks and locks THIS session held,
            // so leaving them for the next one would hand it somebody else's work to react to. What
            // the bridge itself wrote stays, because the next session is the reader it was for.
            $join->pending()?->clearFleetEvents($this->diagnostic(...));

            // Last, so nothing above reads a record that is already gone (cli#346)
            $seat->forgetKey();
        }

        return self::SUCCESS;
    }

    /**
     * The capacity `--capacity` declares: null when omitted, false when it is not usable (#302).
     *
     * A whole number, at least 1. Above 16 is passed on as given: the service stores it as 16 and
     * the join result says what is in effect.
     */
    private function capacityOption(): int|false|null
    {
        $given = $this->stringOption('capacity');

        if ($given === null) {
            if ($this->input->hasParameterOption('--capacity')) {
                $this->diagnostic('--capacity takes a whole number, 1 or more; not joining automatically.');

                return false;
            }

            return null;
        }

        $capacity = preg_match('/^\d+$/', $given) === 1 ? filter_var($given, FILTER_VALIDATE_INT) : false;

        if (! \is_int($capacity) || $capacity < 1) {
            $this->diagnostic('--capacity takes a whole number, 1 or more; not joining automatically.');

            return false;
        }

        return $capacity;
    }

    /**
     * Take this checkout's seat, or say that another bridge already has it (#320).
     *
     * Said once on stderr, which Claude Code keeps as the server's log, and handed to the bridge so
     * the agent hears it at connect and at the join.
     *
     * @param  PendingEvents  $seat  The sink this bridge will write, keyed as the join keys it.
     * @param  int  $waitMilliseconds  How long to keep trying for a seat another bridge holds (#333).
     * @return string|null The warning, or null when this bridge holds the seat.
     */
    private function sharedSinkWarning(PendingEvents $seat, int $waitMilliseconds = 0): ?string
    {
        for ($waited = 0; ; $waited += 100) {
            if ($seat->claimSeat()) {
                return null;
            }

            if ($waited >= $waitMilliseconds) {
                break;
            }

            Sleep::for(100)->milliseconds();
        }

        $holder = $seat->seatHolder();
        $warning = \sprintf(Bridge::SHARED_SINK_WARNING, $holder === null ? 'pid unknown' : 'pid '.$holder);

        $this->diagnostic($warning);

        return $warning;
    }

    /**
     * Whether a stop hook will deliver events to this session at the end of a turn (#306).
     *
     * **Claude Code only.** It is the one harness this bridge wakes through a channel, and so the
     * one whose agent is told what a notice means; every other harness keeps today's instructions
     * and is not inspected. Said once, on stderr, which Claude Code keeps as the server's log, so
     * an operator has a line to grep rather than a silence to interpret.
     *
     * @param  string  $harness  Which harness this process is.
     */
    private function stopHookDelivers(string $harness): bool
    {
        if ($harness !== 'claude') {
            return true;
        }

        $directory = getcwd();

        // Where the session was launched is where Claude Code started this server, measured on
        // 2.1.282 (#299), so the project's settings are read from here
        $hooks = StopHooks::inspect(\is_string($directory) ? $directory : '.');

        foreach ($hooks->report() as $line) {
            $this->diagnostic($line);
        }

        return $hooks->delivers();
    }

    /**
     * The keep-alive schedule `--keep-warm` asks for, or null (#279).
     *
     * **A value it cannot use is said and dropped, never fatal.** A bridge that dies at launch
     * leaves one line on a stderr most harnesses bury, and the session without its fleet tools; one
     * that carries on without keep-alives costs only what the option would have saved.
     *
     * **The turn-end mark is read from the sink the stop hook drains**, keyed the same way, which is
     * why it can be built before anybody joins: the service, the harness and the project are known
     * at launch.
     *
     * @param  string  $service  The service base URL.
     * @param  string  $harness  Which harness this process is.
     */
    private function keepWarm(string $service, string $harness): ?KeepWarm
    {
        $interval = $this->stringOption('keep-warm');
        $ceiling = $this->stringOption('keep-warm-for');

        if ($interval === null) {
            if ($this->input->hasParameterOption('--keep-warm')) {
                // Given with no value: Laravel reads a bare `--keep-warm` as null, not as an error
                $this->diagnostic('--keep-warm takes a whole number of minutes, at least 1; keep-alives are off.');
            } elseif ($ceiling !== null) {
                $this->diagnostic('--keep-warm-for applies only with --keep-warm; keep-alives are off.');
            }

            return null;
        }

        if ($harness !== 'claude') {
            // The one harness a bridge can wake: nothing else reads `claude/channel` (cli#197)
            $this->diagnostic('--keep-warm applies only to Claude Code; keep-alives are off.');

            return null;
        }

        $pending = new PendingEvents($service, $harness, $this->stringOption('project'), useRecordedKey: true);

        $keepWarm = KeepWarm::fromOptions($interval, $ceiling, $pending->turnEndedAt(...), time(...));

        if (\is_string($keepWarm)) {
            $this->diagnostic($keepWarm);

            return null;
        }

        return $keepWarm;
    }

    /**
     * Ask the bridge to stop when the harness asks this process to.
     *
     * A harness terminating a bridge sends `SIGTERM`; a person at a terminal sends `SIGINT`. Either
     * way the session should end rather than be swept minutes later, so the signal sets a flag the
     * loop reads and the `finally` above does the work.
     *
     * Where `pcntl` is unavailable this does nothing, and the session falls back to being swept --
     * which is why it is checked rather than assumed.
     *
     * @param  Bridge  $bridge  The loop to stop.
     */
    private function listenForSignals(Bridge $bridge): void
    {
        if (! \function_exists('pcntl_async_signals') || ! \function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);

        foreach ([SIGTERM, SIGINT] as $signal) {
            pcntl_signal($signal, static function () use ($bridge): void {
                $bridge->stop();
            });
        }
    }

    /**
     * What the agent reads at connect when its application was on the fleet and has not rejoined (#333).
     *
     * @param  int  $joinedAt  When the earlier join was recorded, as a Unix timestamp.
     */
    private function droppedNotice(int $joinedAt): string
    {
        $minutes = intdiv(max(0, time() - $joinedAt), 60);

        return \sprintf(
            'A Cursor bridge joined the Robot Council fleet from this machine %s, and this bridge has not '
            .'rejoined: Cursor was restarted since, or the rejoin could not be made. Tell your operator on your '
            .'next turn, and if they want this application back on the fleet, call `join`.',
            $minutes === 0 ? 'less than a minute ago' : ($minutes === 1 ? '1 minute ago' : $minutes.' minutes ago'),
        );
    }

    /**
     * Tell the agent at connect, and the operator on stderr, how this bridge came up (#333).
     */
    private function tellAtConnect(Bridge $bridge, string $notice): void
    {
        $bridge->tellAtConnect($notice);

        $this->diagnostic($notice);
    }

    /**
     * Say something to the operator, never to the protocol stream.
     *
     * @param  string  $message  What to say.
     */
    private function diagnostic(string $message): void
    {
        Stderr::say($this->output->getOutput(), $message);
    }

    /**
     * The service to bridge to.
     */
    private function resolveService(): ?string
    {
        $given = $this->option('service');

        if (! \is_string($given) || $given === '') {
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
}
