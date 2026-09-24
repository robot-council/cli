<?php

declare(strict_types=1);

namespace App\Support;

use App\Commands\McpReaderCommand;
use RuntimeException;

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
     * @param  list<string>|string|null  $command  What to run instead of this application, which
     *                                             exists so that a test can stand something else in the child's
     *                                             place. Nothing in the application passes it.
     *
     * @throws RuntimeException When the listener cannot be opened, the child cannot be started, or
     *                          it does not connect and name its token.
     */
    public function __construct($in = null, $errors = null, array|string|null $command = null)
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
            [self::PORT => (string) $this->portOf($listener), self::TOKEN => $token] + $this->environment(),
            // **No shell on either platform, and it took both to learn why.** A string command is
            // wrapped in `cmd.exe` on Windows and in `/bin/sh -c` on POSIX, and `proc_terminate()`
            // then kills the wrapper while the real process keeps running -- holding the harness's
            // stdin. On Windows that also made `proc_close()` block forever waiting for it, measured
            // twice at 600 and 400 seconds; on the ubuntu cell it left four readers behind in one
            // run. Passing the command as an **array** makes PHP start the process directly, so
            // there is no wrapper to kill instead. `bypass_shell` says the same thing for Windows.
            ['bypass_shell' => true]
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
     * The reader itself, not a shell wrapping it, because it is started with `bypass_shell`.
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

        proc_terminate($this->process);

        // Returns at once, because the child above is the real process rather than a shell wrapping
        // one. That is the whole reason `bypass_shell` is passed when it is started.
        proc_close($this->process);
    }

    /**
     * The command that re-invokes this application as the reader.
     *
     * @return list<string> The binary, the script, and the hidden command's name.
     */
    private function command(): array
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

        // An array rather than a string, so no shell parses it and nothing needs escaping here.
        return [\PHP_BINARY, $script, McpReaderCommand::COMMAND];
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
