<?php

declare(strict_types=1);

namespace App\Commands;

use App\Support\Bridge;
use App\Support\Checkout;
use App\Support\Credentials\Credentials;
use App\Support\Credentials\InstallationChoice;
use App\Support\FleetDelivery;
use App\Support\FleetFollower;
use App\Support\Joined;
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
    {--harness= : Which enrolled harness this process is, when detection cannot tell}
    {--auto-join : Join the fleet at launch, for a checkout that should join every time it starts}
    {--role= : With --auto-join, the role to ask for: build, ci or coordinator}')]
final class McpCommand extends Command
{
    /**
     * The sink the joined session's follower writes, once somebody has joined.
     */
    private ?PendingEvents $pending = null;

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
    public function handle(Factory $http, Credentials $credentials): int
    {
        $service = $this->resolveService();

        if ($service === null) {
            $this->diagnostic('Pass --service, or set ROBOT_COUNCIL_SERVICE, with the URL of your fleet.');

            return self::FAILURE;
        }

        // The same cascade `InstallationChoice` uses to pick the credential, so the sink is keyed by
        // the harness this process actually is rather than by a second opinion.
        $harness = MachineIdentity::resolveHarness($this->stringOption('harness')) ?? 'unknown-harness';

        $bridge = new Bridge(
            null,
            $service,
            Bridge::HEARTBEAT_SECONDS,

            // **Claude Code only**, the one harness that reads `claude/channel`. A session started
            // without `--channels` drops the notices, and the stop hook delivers as before (cli#62).
            channel: $harness === 'claude',
            join: fn (array $arguments): Joined => $this->join($http, $credentials, $service, $harness, $arguments),
        );

        $this->listenForSignals($bridge);

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

        try {
            // After the signal handlers, so a `SIGTERM` arriving during the join's network calls
            // is caught and the session it started is still ended below
            if ($this->option('auto-join') === true) {
                $bridge->joinNow([
                    'role' => $this->stringOption('role'),
                    'repository' => null,
                    'work_location' => null,
                ], $this->diagnostic(...));
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

            // The session must not outlive the harness. Ending it releases its tasks and locks now
            // rather than leaving them held until the presence sweep notices. A bridge that never
            // joined has nothing to end, and leaves nothing on the fleet.
            $bridge->session()?->end();

            // And the sink goes with it. Unread events name tasks and locks THIS session held, so
            // leaving them for the next one would hand it somebody else's work to react to.
            $this->pending?->forget();
        }

        return self::SUCCESS;
    }

    /**
     * Join the fleet: choose the credential, start the session, ask for a role, and open the sink.
     *
     * **A session that started is ended if anything after it fails**, so a join that reports
     * failure never leaves a session behind it on the fleet.
     *
     * @param  Factory  $http  The HTTP client.
     * @param  Credentials  $credentials  Where the installation credential lives.
     * @param  string  $service  The service's base URL.
     * @param  string  $harness  Which harness this process is.
     * @param  array{role: string|null, repository: string|null, work_location: string|null}  $arguments  What `join` was given.
     * @return Joined The started session and its follower.
     *
     * @throws RuntimeException With a sentence for the agent, when joining failed.
     */
    private function join(Factory $http, Credentials $credentials, string $service, string $harness, array $arguments): Joined
    {
        // One credential per harness per fleet, so this process has to say which harness it is.
        // It refuses rather than guessing, including when only one is stored -- #21 weighed that
        // and chose one rule with nothing inferred. `InstallationChoice` throws a sentence naming
        // what is missing, which is what `join` hands the agent.
        $installation = new InstallationChoice($credentials)->for($service, $this->stringOption('harness'));

        $session = new Session($http, $service, $installation);

        // What `join` was told wins; then the flags; then the checkout this process runs in, so 37
        // hand-maintained config entries stop needing to be maintained. Per field, and neither may
        // keep the session from starting.
        $givenRepository = $arguments['repository'] ?? $this->stringOption('repository');
        $givenLocation = $arguments['work_location'] ?? $this->stringOption('work-location');

        [$repository, $workLocation] = Checkout::resolve($givenRepository, $givenLocation);

        $notes = array_values(array_filter([
            $this->refusal('repository', $givenRepository, $repository),
            $this->refusal('work location', $givenLocation, $workLocation),
        ]));

        foreach ($notes as $note) {
            $this->diagnostic($note);
        }

        try {
            $session->start($this->stringOption('project'), $repository, $workLocation);
        } catch (RuntimeException $runtimeException) {
            throw $runtimeException;
        } catch (Throwable) {
            throw new RuntimeException('The fleet could not be reached.');
        }

        try {
            $role = $arguments['role'];

            if ($role !== null && $role !== 'build') {
                $notes[] = $this->requestRole($session, $role);
            }

            // The sink the follower writes and `robot-council pending` drains, keyed by the same
            // three things that identify this bridge, so a stop hook beside it finds the same file.
            $this->pending = new PendingEvents($service, $harness, $this->stringOption('project'));

            $follower = new FleetFollower($session, $service, $this->pending);

            // **Said at join, and only when the answer is a definite no.** A fleet can be wired
            // correctly and still deliver nothing, because posting a directive needs the
            // `coordinator` role and no session starts with it (cli#113). Moved here from launch
            // with everything else a session needs; still on stderr, never on stdout.
            $delivery = FleetDelivery::warning($session->fleetCanDirect());

            if ($delivery !== null) {
                $this->diagnostic($delivery);
            }
        } catch (Throwable $throwable) {
            $session->end();
            $this->pending = null;

            throw new RuntimeException($throwable instanceof RuntimeException ? $throwable->getMessage() : 'Joining failed after the session started, so it was ended.', $throwable->getCode(), $throwable);
        }

        $summary = \sprintf(
            "Joined the fleet as session %s%s. The fleet's tools are available now.",
            $session->id() ?? 'unknown',
            $repository === null ? '' : \sprintf(', working in %s%s', $repository, $workLocation === null ? '' : ' at '.$workLocation)
        );

        return new Joined($session, $follower, implode(' ', [$summary, ...$notes]));
    }

    /**
     * Ask for a role, and say in a sentence what came of it.
     *
     * **Two calls, not atomic, and the gap is reported rather than hidden.** A session that started
     * and then failed to ask for its role is live on `build`, which holds less than was asked for and
     * never more; `join` still says so instead of reporting a plain success.
     */
    private function requestRole(Session $session, string $role): string
    {
        try {
            $answer = $session->requestRole($role);
        } catch (RuntimeException $runtimeException) {
            return \sprintf('It joined as build: asking for %s failed. %s', $role, $runtimeException->getMessage());
        }

        return $answer['pending']
            ? \sprintf("It asked for the %s role; an administrator decides, and until then it holds %s's abilities.", $role, $answer['role'] ?? 'build')
            : \sprintf('It holds the %s role already.', $answer['role'] ?? $role);
    }

    /**
     * A sentence saying a value was given and could not be used, or null when there is nothing to say.
     *
     * **Silence would be the worst outcome here.** Somebody wrote this value out, and dropping it
     * without a word gives them a session that is missing it and no reason to look -- which is the
     * label that stays wrong for months because nothing ever contradicts it.
     */
    private function refusal(string $field, ?string $given, ?string $resolved): ?string
    {
        if ($given === null || $resolved !== null) {
            return null;
        }

        return \sprintf('The %s %s is not a shape the fleet accepts, so it was left unset.', $field, $given);
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
