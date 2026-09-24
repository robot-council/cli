<?php

declare(strict_types=1);

namespace App\Commands;

use App\Support\Bridge;
use App\Support\Checkout;
use App\Support\Credentials\Credential;
use App\Support\Credentials\Credentials;
use App\Support\Credentials\InstallationChoice;
use App\Support\FleetDelivery;
use App\Support\FleetFollower;
use App\Support\MachineIdentity;
use App\Support\PendingEvents;
use App\Support\Session;
use App\Support\StdinReader;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Http\Client\Factory;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
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
    {--harness= : Which enrolled harness this process is, when detection cannot tell}')]
final class McpCommand extends Command
{
    /**
     * Run the bridge until stdin closes or a signal arrives.
     *
     * @param  Factory  $http  The HTTP client.
     * @param  Credentials  $credentials  Where the installation credential lives.
     * @return int The exit code.
     */
    public function handle(Factory $http, Credentials $credentials): int
    {
        $service = $this->resolveService();

        if ($service === null) {
            $this->diagnostic('Pass --service, or set ROBOT_COUNCIL_SERVICE, with the URL of your fleet.');

            return self::FAILURE;
        }

        // One credential per harness per fleet, so this process has to say which harness it is.
        // It refuses rather than guessing, including when only one is stored -- #21 weighed that
        // and chose one rule with nothing inferred.
        try {
            $installation = new InstallationChoice($credentials)->for($service, $this->stringOption('harness'));
        } catch (RuntimeException $runtimeException) {
            $this->diagnostic($runtimeException->getMessage());

            return self::FAILURE;
        }

        $session = new Session($http, $service, $installation);

        // Read from the checkout this process is already running in, so 37 hand-maintained config
        // entries stop needing to be maintained -- and stop drifting, which matters more. An
        // explicit flag wins per field, and neither may keep the session from starting.
        [$repository, $workLocation] = Checkout::resolve(
            $this->stringOption('repository'),
            $this->stringOption('work-location'),
        );

        $this->sayWhatWasRefused('repository', $this->stringOption('repository'), $repository);
        $this->sayWhatWasRefused('work-location', $this->stringOption('work-location'), $workLocation);

        try {
            $session->start($this->stringOption('project'), $repository, $workLocation);
        } catch (Throwable $throwable) {
            $this->diagnostic($throwable instanceof RuntimeException
                ? $throwable->getMessage()
                : 'Could not start a session.');

            return self::FAILURE;
        }

        // The sink the follower writes and `robot-council pending` drains, keyed by the same three
        // things that identify this bridge, so a stop hook beside it finds the same file (cli#60).
        // The same cascade `InstallationChoice` just used to pick the credential, so the sink is
        // keyed by the harness this process actually is rather than by a second opinion.
        $harness = MachineIdentity::resolveHarness($this->stringOption('harness')) ?? 'unknown-harness';

        $pending = new PendingEvents($service, $harness, $this->stringOption('project'));

        $bridge = new Bridge(
            $session,
            $service,
            Bridge::HEARTBEAT_SECONDS,
            new FleetFollower($session, $service, $pending)
        );

        $this->listenForSignals($bridge);

        // **Said once, at startup, and only when the answer is a definite no.** A fleet can be wired
        // correctly and still deliver nothing: `FleetFollower::ALWAYS` is `['directive']`, and
        // posting one needs the `coordinator` role, which an administrator gives a session and which
        // no session starts with. So unless somebody is running as the coordinator, no directive can
        // be posted -- and a hook that finds an empty sink cannot tell that from a fleet with
        // nothing to say (cli#113).
        //
        // **A snapshot, not a standing property, since `robot-council/core#223`.** The field answers
        // whether a coordinator is running *now* rather than whether one could ever exist, so it
        // flips when the fleet's one coordinator restarts. Said once anyway, deliberately: the
        // decision is recorded on that ticket, and a stale `false` is one line on stderr while the
        // case `#113` exists for -- no coordinator running at startup -- still reports correctly.
        //
        // **Not a warning about THIS session's role.** A bridge that only ever receives runs as
        // `build` and is correctly configured, which is the common case; warning on that would train
        // people to ignore the line. `null` -- an older service, or a request that did not land --
        // says nothing at all.
        //
        // **Placed after the signal handlers, and that position is deliberate.** It is a network
        // call, bounded by the client's default 10s connect and 30s read timeouts, and `end()` is
        // only reachable from the `finally` below. Made before `listenForSignals()`, a `SIGTERM`
        // arriving during a stalled request would take the default action and leave a started
        // session alive on the fleet until the presence sweep -- against this class's own rule
        // that ending is not best-effort.
        $delivery = FleetDelivery::warning($session->fleetCanDirect());

        if ($delivery !== null) {
            $this->diagnostic($delivery);
        }

        // **The bridge reads a socket, not stdin.** A child does the blocking read, because
        // `stream_select()` does not honor its timeout on a Windows pipe and the loop would then
        // only turn over when the harness sends a frame -- so an idle agent would get no heartbeat,
        // no renewal and no directives, which is the agent all three exist for (#131).
        try {
            $reader = new StdinReader;
        } catch (RuntimeException $runtimeException) {
            $this->diagnostic($runtimeException->getMessage());

            $session->end();

            return self::FAILURE;
        }

        try {
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

            // The session must not outlive the harness. Ending it releases its tasks and locks now
            // rather than leaving them held until the presence sweep notices.
            $session->end();

            // And the sink goes with it. Unread events name tasks and locks THIS session held, so
            // leaving them for the next one would hand it somebody else's work to react to.
            $pending->forget();
        }

        return self::SUCCESS;
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
     * Say when a flag was given and could not be used.
     *
     * @param  string  $flag  The flag's name, without its dashes.
     * @param  string|null  $given  What the operator wrote, when they wrote anything.
     * @param  string|null  $resolved  What survived the fleet's rules.
     */
    private function sayWhatWasRefused(string $flag, ?string $given, ?string $resolved): void
    {
        if ($given === null || $resolved !== null) {
            return;
        }

        // **Silence would be the worst outcome here.** Somebody wrote this value out, and dropping
        // it without a word gives them a session that is missing it and no reason to look -- which
        // is the label that stays wrong for months because nothing ever contradicts it.
        $this->diagnostic(sprintf(
            '--%s=%s is not a shape the fleet accepts, so it was left unset.',
            $flag,
            $given
        ));
    }

    /**
     * Say something to the operator, never to the protocol stream.
     *
     * @param  string  $message  What to say.
     */
    private function diagnostic(string $message): void
    {
        $output = $this->output->getOutput();

        if ($output instanceof ConsoleOutputInterface) {
            $output->getErrorOutput()->writeln('robot-council: '.$message);

            return;
        }

        // A last resort that still avoids stdout. Reached when the command runs under a test
        // harness whose output is a single buffer rather than a console with two streams.
        file_put_contents('php://stderr', 'robot-council: '.$message."\n");
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
