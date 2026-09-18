<?php

declare(strict_types=1);

namespace App\Commands;

use App\Support\Bridge;
use App\Support\Credentials\Credential;
use App\Support\Credentials\Credentials;
use App\Support\Session;
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
    {--project= : The repository or workspace this session belongs to}')]
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

        $installation = $credentials->store()->get($service);

        if (! $installation instanceof Credential) {
            $this->diagnostic('This machine is not enrolled against that service. Run `robot-council enroll`.');

            return self::FAILURE;
        }

        $session = new Session($http, $service, $installation);

        try {
            $session->start($this->stringOption('project'));
        } catch (Throwable $throwable) {
            $this->diagnostic($throwable instanceof RuntimeException
                ? $throwable->getMessage()
                : 'Could not start a session.');

            return self::FAILURE;
        }

        $bridge = new Bridge($session, $service);

        $this->listenForSignals($bridge);

        try {
            $bridge->run(STDIN, STDOUT, $this->diagnostic(...));
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
            // The session must not outlive the harness. Ending it releases its tasks and locks now
            // rather than leaving them held until the presence sweep notices.
            $session->end();
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
     * @param  string  $message  What went wrong.
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
