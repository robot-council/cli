<?php

declare(strict_types=1);

namespace App\Commands;

use App\Support\Bridge;
use App\Support\Checkout;
use App\Support\Credentials\Credentials;
use App\Support\FleetJoin;
use App\Support\MachineIdentity;
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

        $bridge = new Bridge(
            null,
            $service,
            Bridge::HEARTBEAT_SECONDS,

            // **Claude Code only**, the one harness that reads `claude/channel`. A session started
            // without `--channels` drops the notices, and the stop hook delivers as before (cli#62).
            channel: $harness === 'claude',
            join: $join(...),

            // Read when an agent starts a task, not once at launch: a lane switches branches.
            branch: static fn (): ?string => Checkout::branch(),
        );

        if ($this->stringOption('role') !== null && $this->option('auto-join') !== true) {
            // A role is asked for at the join, and without `--auto-join` this process does not join
            $this->diagnostic('--role applies only with --auto-join; pass the role to the `join` tool instead.');
        }

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

            // And the fleet events go with it. Unread ones name tasks and locks THIS session held,
            // so leaving them for the next one would hand it somebody else's work to react to. What
            // the bridge itself wrote stays, because the next session is the reader it was for.
            $join->pending()?->clearFleetEvents($this->diagnostic(...));
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
