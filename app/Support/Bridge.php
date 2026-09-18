<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;
use Throwable;

/**
 * Forwards MCP messages between a harness's stdio and the coordination service.
 *
 * Separated from the command so that the loop can be driven by a test with ordinary streams rather
 * than the real `STDIN`, which a test process does not own.
 *
 * **Only protocol messages go to stdout.** A harness parses that stream, so one stray line -- a
 * warning, a debug dump, a PHP notice -- is not a visible error but a malformed message, on
 * whichever machine happens to have a slightly different configuration. Everything else goes to
 * stderr.
 */
final class Bridge
{
    /**
     * How long to wait for input before doing periodic work, in seconds.
     *
     * The loop is otherwise blocked on stdin, and a heartbeat that only fires when a tool is called
     * would never fire for an idle agent -- which is exactly the agent the presence sweep would
     * mark gone.
     */
    public const int TICK_SECONDS = 5;

    /**
     * How often to tell the service this session is alive.
     */
    public const int HEARTBEAT_SECONDS = 60;

    /**
     * How much remaining token life counts as "renew now".
     *
     * Renewing early means an ordinary tool call does not pay for the round trip, and leaves the
     * 401 path as the backstop for a token revoked rather than expired.
     */
    public const int RENEW_WITHIN_SECONDS = 300;

    /**
     * How long to wait before trying a failed renewal again.
     */
    public const int RENEW_RETRY_SECONDS = 30;

    /**
     * How much to read from stdin at a time.
     *
     * Larger than the 8,192-byte socket buffer measured on macOS, so an ordinary message usually
     * arrives in one read -- but correctness does not depend on that, because the loop buffers
     * until a newline either way.
     */
    public const int READ_BYTES = 65536;

    /**
     * @param  Session  $session  The started session this bridge forwards through.
     * @param  string  $service  The service's base URL.
     */
    public function __construct(
        private readonly Session $session,
        private readonly string $service,
    ) {}

    /**
     * When the next heartbeat is due, as a Unix timestamp.
     */
    private int $nextHeartbeat = 0;

    /**
     * The earliest a renewal may be attempted again after one failed.
     */
    private int $nextRenewAttempt = 0;

    /**
     * Whether a signal has asked the loop to stop.
     */
    private bool $stopping = false;

    /**
     * Ask the loop to finish after the message it is handling.
     */
    public function stop(): void
    {
        $this->stopping = true;
    }

    /**
     * Pump messages until stdin closes or a signal arrives.
     *
     * @param  resource  $in  Where messages arrive.
     * @param  resource  $out  Where responses go. Protocol messages only.
     * @param  callable(string):void  $diagnostic  Where anything else goes.
     */
    public function run($in, $out, callable $diagnostic): void
    {
        $this->nextHeartbeat = time() + self::HEARTBEAT_SECONDS;

        stream_set_blocking($in, false);

        $buffer = '';

        while (! $this->stopping) {
            $readable = [$in];
            $write = null;
            $except = null;

            // A timeout rather than a blocking read, so periodic work happens for an agent sitting
            // idle -- which is the agent whose session would otherwise be swept.
            //
            // **Suppressed deliberately.** A signal arriving during the call raises
            // `stream_select(): Unable to select [4]: Interrupted system call`, and Laravel's
            // `HandleExceptions` turns any warning into an `ErrorException` -- which Collision then
            // renders to STDOUT, corrupting the protocol stream with ~900 bytes of formatted
            // exception. Measured on the documented SIGTERM path, which is how a harness stops this.
            $ready = @stream_select($readable, $write, $except, self::TICK_SECONDS);

            if ($ready === false) {
                // Interrupted by a signal; the handler will have set `$stopping`
                continue;
            }

            if ($ready > 0) {
                $chunk = fread($in, self::READ_BYTES);

                if ($chunk === false || ($chunk === '' && feof($in))) {
                    // stdin closed: the harness has gone, and the session should not outlive it
                    return;
                }

                $buffer .= $chunk;

                // **Read by chunk and split here, never `fgets`.** `stream_select` reports a stream
                // readable when ANY bytes have arrived, not a whole line, and on a non-blocking
                // stream `fgets` hands back whatever is there. Measured: a 200,065-byte
                // `tools/call` arrived as 24 fragments at the socket buffer's 8,192 bytes, and
                // every one was forwarded as its own malformed request -- 0 valid messages of 24.
                // A message only leaves here once its terminating newline has.
                while (($break = strpos($buffer, "\n")) !== false) {
                    $line = trim(substr($buffer, 0, $break));

                    $buffer = substr($buffer, $break + 1);

                    if ($line !== '') {
                        $this->forward($line, $out, $diagnostic);
                    }
                }
            }

            $this->periodic($diagnostic);
        }
    }

    /**
     * Send one message on and write back what the service said.
     *
     * @param  string  $message  One JSON-RPC message, as the harness wrote it.
     * @param  resource  $out  Where the response goes.
     * @param  callable(string):void  $diagnostic  Where anything else goes.
     */
    private function forward(string $message, $out, callable $diagnostic): void
    {
        try {
            $response = $this->post($message);

            // One renewal and one retry on a 401, never a loop: a second consecutive refusal means
            // the installation itself is revoked, and retrying forever would hide that
            if ($response === 401) {
                $this->session->renew();

                $response = $this->post($message);

                if ($response === 401) {
                    throw new RuntimeException('The service refused this session twice. The installation may have been revoked.');
                }
            }

            if (\is_string($response)) {
                $this->reply($response, $out, $diagnostic);
            }
        } catch (Throwable $throwable) {
            // To stderr, never to stdout: a harness parsing stdout would read a diagnostic as a
            // malformed protocol message rather than as an error
            $diagnostic($throwable->getMessage());
        }
    }

    /**
     * Write one reply, but only if it is something a harness can parse.
     *
     * Three things reach here that are not protocol messages, and all three were measured:
     *
     * - **An empty body.** An MCP *notification* carries no `id`, so `laravel/mcp` answers `202`
     *   with nothing. Writing that put a bare newline on stdout -- and
     *   `notifications/initialized` is mandatory, so it happened on every single session.
     * - **A multi-line body.** An nginx `502` page came back as seven lines, none of them JSON.
     * - **Anything that is not JSON at all**, such as a maintenance page or a proxy interstitial.
     *
     * A harness parses this stream, so each of those is a malformed message rather than a visible
     * error. They go to the operator instead.
     *
     * @param  string  $response  What the service sent back.
     * @param  resource  $out  Where protocol messages go.
     * @param  callable(string):void  $diagnostic  Where anything else goes.
     */
    private function reply(string $response, $out, callable $diagnostic): void
    {
        $trimmed = trim($response);

        if ($trimmed === '') {
            // A notification, answered with 202 and no body. Nothing to relay, and nothing wrong.
            return;
        }

        if (str_contains($trimmed, "\n") || ! \is_array(json_decode($trimmed, true))) {
            $diagnostic('The service answered with something that is not a protocol message.');

            return;
        }

        // The only thing this class ever writes to `$out`
        fwrite($out, $trimmed."\n");
    }

    /**
     * Post one message, returning the body, or 401 when the session was refused.
     *
     * @param  string  $message  The JSON-RPC message.
     * @return string|int The response body, or the status when it was 401.
     */
    private function post(string $message): string|int
    {
        $response = $this->session->request()
            ->withBody($message, 'application/json')
            ->post($this->service.'/robot-council/api/mcp');

        if ($response->status() === 401) {
            return 401;
        }

        return $response->body();
    }

    /**
     * Heartbeat and renew, on their own schedules.
     *
     * @param  callable(string):void  $diagnostic  Where anything else goes.
     */
    private function periodic(callable $diagnostic): void
    {
        if (time() >= $this->nextHeartbeat) {
            $this->session->heartbeat();

            $this->nextHeartbeat = time() + self::HEARTBEAT_SECONDS;
        }

        if ($this->session->expiringWithin(self::RENEW_WITHIN_SECONDS) && time() >= $this->nextRenewAttempt) {
            try {
                $this->session->renew();
            } catch (Throwable $failure) {
                // **Backed off, because a failed renewal does not move the expiry.** Without this,
                // `expiringWithin()` stays true and the loop retries on every pass -- measured at
                // 10 attempts a minute while idle, and at the message rate when busy, which can
                // cross `core`'s own 60-a-minute limit and lock the installation out of starting
                // sessions at all. The same reasoning the 401 backstop already applies, which this
                // path had not.
                $this->nextRenewAttempt = time() + self::RENEW_RETRY_SECONDS;

                $diagnostic($failure->getMessage());
            }
        }
    }
}
