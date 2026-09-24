<?php

declare(strict_types=1);

namespace App\Support;

use App\Commands\McpReaderCommand;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

/**
 * Reads stdin in a child process and hands the lines back over a socket.
 *
 * **Because `stream_select()` does not honor its timeout on a Windows pipe.** `Bridge::run()` paces
 * its heartbeat, its renewal and its feed follow on that timeout, and on Windows the loop therefore
 * only turns over when the harness sends a frame. Measured for #131: a harness-spawned server sat
 * idle for 10.34 seconds and woke zero times, where a 200ms timeout owes about 52, while the same
 * loop over a **socket** woke 10 times in 2.0 seconds. `stream_set_blocking()` also fails outright
 * on such a pipe, so a poll loop is not an alternative -- one `fread()` blocked for 2168ms.
 *
 * So a child does the blocking read and the bridge selects on a socket, where the timeout fires.
 *
 * **On every platform, not only Windows.** A second implementation of the read path that runs on
 * one platform can diverge from the other silently, and the read path is load-bearing.
 *
 * The child is this same application, re-invoked with a hidden command, rather than a script file
 * beside it: the application is installed through Composer's `vendor/bin` proxy and can also be
 * built into a single-file executable, and inside one `__DIR__` is a `phar://` path that nothing
 * can usefully spawn.
 */
final class StdinReader
{
    /**
     * The environment variable carrying the port the child connects back on.
     */
    public const string PORT = 'ROBOT_COUNCIL_READER_PORT';

    /**
     * The environment variable carrying the token the child must name.
     *
     * **In the environment, never in argv.** Another process on the machine can read a command
     * line -- measured on Windows, 476 of 628 were readable -- and this repository already keeps a
     * credential off argv for the same reason. The token is not a credential, but what it guards
     * is the bridge's own protocol stream.
     */
    public const string TOKEN = 'ROBOT_COUNCIL_READER_TOKEN';

    /**
     * How long to wait for the child to connect back, in seconds.
     */
    public const int ACCEPT_TIMEOUT_SECONDS = 10;

    /**
     * How long to spend killing the child before giving up on it.
     */
    public const int KILL_TIMEOUT_SECONDS = 5;

    /**
     * The connected socket the bridge reads, once the child has named its token.
     *
     * @var resource
     */
    private $stream;

    /**
     * The child process handle.
     *
     * @var resource
     */
    private $process;

    /**
     * Start the child and wait for it to connect.
     *
     * @param  resource  $in  The stdin to hand the child, normally `STDIN`.
     * @param  resource  $errors  Where the child's own output goes, normally `STDERR`.
     * @param  string|null  $command  What to run instead of this application, which exists so that
     *                                a test can stand something else in the child's place. Nothing
     *                                in the application passes it.
     *
     * @throws RuntimeException When the listener cannot be opened, the child cannot be started, or
     *                          it does not connect and name its token.
     */
    public function __construct($in = null, $errors = null, ?string $command = null)
    {
        $in ??= \STDIN;
        $errors ??= \STDERR;

        $listener = @stream_socket_server('tcp://127.0.0.1:0', $code, $error);

        if ($listener === false) {
            throw new RuntimeException(sprintf('Could not listen for the stdin reader: %s', $error));
        }

        $token = bin2hex(random_bytes(32));

        $process = proc_open(
            $command ?? $this->command(),
            [
                // The child reads the very stdin this process was handed by the harness.
                0 => $in,
                // **Its stdout goes to stderr, not to stdout.** A harness parses this process's
                // stdout, so a stray line from the child would be a malformed protocol message
                // rather than a visible error.
                1 => $errors,
                2 => $errors,
            ],
            $pipes,
            null,
            [self::PORT => (string) $this->portOf($listener), self::TOKEN => $token] + $this->environment()
        );

        if (! \is_resource($process)) {
            fclose($listener);

            throw new RuntimeException('Could not start the stdin reader.');
        }

        $stream = @stream_socket_accept($listener, self::ACCEPT_TIMEOUT_SECONDS);

        // Accepted or not, nothing else may connect: the reader is one child, once.
        fclose($listener);

        if (! \is_resource($stream)) {
            proc_terminate($process);
            proc_close($process);

            throw new RuntimeException('The stdin reader did not connect.');
        }

        $greeting = fgets($stream);

        if (! \is_string($greeting) || ! hash_equals($token, trim($greeting))) {
            fclose($stream);
            proc_terminate($process);
            proc_close($process);

            throw new RuntimeException('Something other than the stdin reader connected.');
        }

        $this->stream = $stream;
        $this->process = $process;
    }

    /**
     * The stream to read messages from, in place of stdin.
     *
     * @return resource The connected socket.
     */
    public function stream()
    {
        return $this->stream;
    }

    /**
     * The child's process id, for a diagnostic or a test that needs to look at it.
     *
     * On Windows this is the intermediate shell `proc_open` starts, not the PHP process beneath
     * it; both carry the same command line, which is what matters for anything reading one.
     */
    public function pid(): ?int
    {
        if (! \is_resource($this->process)) {
            return null;
        }

        return proc_get_status($this->process)['pid'];
    }

    /**
     * Stop the child and close the socket.
     *
     * **Called whatever ended the loop**, including a signal, because the child holds the harness's
     * stdin and would otherwise outlive the bridge that started it. The child also exits on its own
     * when a write to this socket fails, which covers a bridge that died without getting here.
     */
    public function stop(): void
    {
        if (\is_resource($this->stream)) {
            fclose($this->stream);
        }

        if (! \is_resource($this->process)) {
            return;
        }

        // Read before terminating, while the handle still reports one.
        $pid = $this->pid();

        proc_terminate($this->process);

        // **On Windows `proc_terminate()` kills the shell `proc_open` started, not the PHP process
        // beneath it.** The child then survives, and `proc_close()` blocks forever waiting for it:
        // measured here, a run sat for 600 seconds and left the reader orphaned and still holding
        // the harness's stdin. Killing the tree is what actually ends it.
        if (\PHP_OS_FAMILY === 'Windows' && $pid !== null) {
            try {
                Process::timeout(self::KILL_TIMEOUT_SECONDS)->run(['taskkill', '/T', '/F', '/PID', (string) $pid]);
            } catch (Throwable) {
                // The bridge is stopping either way, and there is nothing better to try.
            }
        }

        proc_close($this->process);
    }

    /**
     * The command that re-invokes this application as the reader.
     */
    private function command(): string
    {
        // Inside a single-file build this is the executable itself; otherwise it is the script the
        // process was started from, which is Composer's `vendor/bin` proxy for an installed copy.
        // Inside a single-file build, the executable is the archive itself.
        $script = \Phar::running(false);

        if ($script === '') {
            // The file this package declares in its `bin`, which Composer proxies as
            // `vendor/bin/robot-council`. `base_path()` finds it in a checkout and in an installed
            // copy alike, where `SCRIPT_FILENAME` would be the proxy in one and a test runner in
            // the other.
            $script = base_path('robot-council');

            if (! is_file($script)) {
                throw new RuntimeException('Could not find this application in order to re-invoke it.');
            }
        }

        return sprintf('%s %s %s', escapeshellarg(\PHP_BINARY), escapeshellarg($script), McpReaderCommand::COMMAND);
    }

    /**
     * The port a listener was given.
     *
     * @param  resource  $listener  The listening socket.
     */
    private function portOf($listener): int
    {
        $name = (string) stream_socket_get_name($listener, false);

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    /**
     * The environment the child inherits.
     *
     * Passed explicitly because handing `proc_open` an environment replaces it rather than adding
     * to it, and a child with no `PATH` cannot find anything.
     *
     * @return array<string, string>
     */
    private function environment(): array
    {
        return getenv();
    }
}
