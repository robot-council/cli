<?php

declare(strict_types=1);

namespace App\Commands;

use App\Support\StdinReader;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use LaravelZero\Framework\Commands\Command;

/**
 * Reads stdin with a blocking read and forwards each line to the bridge that started it.
 *
 * **Not run by a person.** `Support\StdinReader` starts it, hands it the harness's stdin, and tells
 * it where to connect through the environment. It exists because `stream_select()` does not honor
 * its timeout on a Windows pipe, so a bridge cannot both block on stdin and wake on a timer in one
 * process; #131 records the measurements and the decision.
 *
 * **Nothing here may reach stdout.** The parent's stdout is the harness's protocol stream, and this
 * process was handed the parent's stderr in place of its own stdout precisely so that a stray line
 * cannot become a malformed message. Lines go to the socket and nowhere else.
 */
#[Description('Internal: read stdin for a running bridge')]
#[Signature('mcp:reader')]
final class McpReaderCommand extends Command
{
    /**
     * What `Support\StdinReader` invokes.
     */
    public const string COMMAND = 'mcp:reader';

    /**
     * How much to read at a time, matching the bridge's own read size.
     */
    public const int READ_BYTES = 65536;

    /**
     * Forward stdin until it closes or the bridge goes.
     *
     * @return int The exit code.
     */
    public function handle(): int
    {
        $port = (int) (getenv(StdinReader::PORT) ?: 0);
        $token = getenv(StdinReader::TOKEN);

        if ($port <= 0 || ! \is_string($token) || $token === '') {
            $this->error('mcp:reader is started by the bridge, not by hand.');

            return self::FAILURE;
        }

        $socket = @stream_socket_client(
            sprintf('tcp://127.0.0.1:%d', $port),
            $code,
            $error,
            StdinReader::ACCEPT_TIMEOUT_SECONDS
        );

        if (! \is_resource($socket)) {
            $this->error(sprintf('The stdin reader could not reach the bridge: %s', $error));

            return self::FAILURE;
        }

        // Named first, so a bridge can tell this child from anything else that found the port.
        if (! $this->put($socket, $token."\n")) {
            return self::FAILURE;
        }

        // **A blocking read, deliberately.** It is the whole reason this process exists: it may
        // sit here for as long as the harness is quiet, while the bridge keeps ticking.
        while (true) {
            $chunk = fread(\STDIN, self::READ_BYTES);

            if ($chunk === false || $chunk === '') {
                if (feof(\STDIN)) {
                    break;
                }

                // **A read that timed out is not the end of input (#201).** A harness running on
                // Node hands its child a socket rather than a pipe, and PHP bounds a blocking
                // socket read by `default_socket_timeout` -- 60 seconds -- after which `fread`
                // answers false with the stream still open. Treating that as the end made every
                // idle Claude Code session drop its bridge, and its fleet session, after one quiet
                // minute. Measured: `timed_out` true and `feof` false, with the harness still there.
                if ($chunk === false && ! stream_get_meta_data(\STDIN)['timed_out']) {
                    break;
                }

                continue;
            }

            // **Bytes, not lines.** The bridge splits on newlines itself, because a message can
            // arrive in fragments -- a 200,065-byte call once arrived as 24 of them -- and
            // re-framing here would just be a second place to get that wrong.
            if (! $this->put($socket, $chunk)) {
                return self::FAILURE;
            }
        }

        fclose($socket);

        return self::SUCCESS;
    }

    /**
     * Write to the socket, reporting whether the bridge is still there.
     *
     * A failed write means the bridge has gone, and this child must go with it rather than sit
     * holding the harness's stdin. That is the backstop for a bridge that died without stopping
     * this process itself.
     *
     * @param  resource  $socket  The connection to the bridge.
     * @param  string  $bytes  What to send.
     */
    private function put($socket, string $bytes): bool
    {
        $written = @fwrite($socket, $bytes);

        if ($written === false || $written < \strlen($bytes)) {
            return false;
        }

        return @fflush($socket);
    }
}
