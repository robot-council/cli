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
     * @param  int  $heartbeatSeconds  How long to wait between heartbeats.
     * @param  int  $renewRetrySeconds  How long a refused renewal waits before another is tried.
     *
     * Both intervals are parameters rather than only constants because otherwise nothing can show
     * what they do: the schedule is read from `time()` inside a loop that blocks on
     * `stream_select`, so a test cannot advance the clock from outside it and would have to sit
     * through a real minute -- or, for the retry, half a real one. Passing 0 makes the next pass
     * due, which is what the tests do. Nothing in this application passes anything but the
     * defaults.
     */
    public function __construct(
        private readonly Session $session,
        private readonly string $service,
        private readonly int $heartbeatSeconds = self::HEARTBEAT_SECONDS,

        // Optional, because the loop's own guarantees must not depend on it: a bridge with no
        // follower forwards tool calls exactly as it did before one existed (cli#60).
        private readonly ?FleetFollower $follower = null,
        private readonly int $renewRetrySeconds = self::RENEW_RETRY_SECONDS,
    ) {}

    /**
     * When the next heartbeat is due, as a Unix timestamp.
     */
    private int $nextHeartbeat = 0;

    /**
     * The earliest a renewal may be attempted again.
     */
    private int $nextRenewAttempt = 0;

    /**
     * Whether a role change is still waiting for a renewal to carry it.
     *
     * **Latched rather than acted on once, and the asymmetry with the expiry trigger is the whole
     * reason it exists.** `Session::expiringWithin()` re-derives itself from the token on every
     * pass, so a renewal the backoff refuses is merely postponed and the next pass after the wait
     * fires it again. A role change is a single event the follower's cursor has already moved past
     * before the renewal is even attempted, so the same refusal would DROP it -- one 502 and the
     * session goes back to answering `allows()` from the abilities it held before the administrator
     * decided, for up to the renewal window, which is the defect this whole path exists to close.
     */
    private bool $roleChanged = false;

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
        $this->nextHeartbeat = time() + $this->heartbeatSeconds;

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

            $this->nextHeartbeat = time() + $this->heartbeatSeconds;
        }

        // Read before the renewal rather than after it: a renewal that throws is caught below and
        // backed off, and putting the feed after it would make a service that refuses renewals also
        // stop the feed being read.
        //
        // **And it can bring the renewal forward.** A role decided by an administrator re-mints
        // what the token may do, but this process goes on answering `allows()` from the abilities
        // it was handed at start -- so a promoted session keeps discarding the events it was
        // promoted to hear, for up to the renewal window, while the service would let it act. The
        // follower says when that has happened and the renewal below stops waiting (#129).
        if ($this->follower?->tick($diagnostic) === true) {
            $this->roleChanged = true;
        }

        // **`gone` is final, so there is nothing left for this loop to do correctly.** The service
        // has refused this session's tokens, released its claims and dropped its locks, so every
        // request from here fails and every tool call forwarded fails with it. The bridge does not
        // get quieter about it either: each message takes the 401 path, which renews, retries, and
        // then reports that the **installation** may be revoked -- naming the one thing that is
        // fine. Stopping and saying what actually happened is the honest end (#157).
        //
        // Returning rather than falling through, because renewing a session the fleet has discarded
        // is a round trip that can only be refused.
        if ($this->follower?->sessionHasGone() === true) {
            $diagnostic('The fleet has marked this session gone, so its claims and locks have been released and its token will be refused. Stopping. Nothing is wrong with the installation, and a new session needs a new bridge.');

            $this->stop();

            return;
        }

        if (($this->roleChanged || $this->session->expiringWithin(self::RENEW_WITHIN_SECONDS)) && time() >= $this->nextRenewAttempt) {
            try {
                $this->session->renew();

                // Carried no further: what this session believes it may do now matches what the
                // service minted for it.
                $this->roleChanged = false;

                // **A floor after a SUCCESS, not only after a failure.** `FleetFollower::read()`
                // keeps the previous cursor when a page's own is not an int, so a service answering
                // one that is not re-serves the same page every poll -- and a role change in it
                // would renew on every pass for as long as that lasted, six a minute against the
                // sixty a minute `core` allows an installation. The expiry trigger cannot do this,
                // because a successful renewal moves the expiry an hour out; this one has no such
                // self-limit, so it is given one.
                $this->nextRenewAttempt = time() + $this->renewRetrySeconds;
            } catch (Throwable $failure) {
                // **Backed off, because a failed renewal does not move the expiry.** Without this,
                // `expiringWithin()` stays true and the loop retries on every pass -- measured at
                // 10 attempts a minute while idle, and at the message rate when busy, which can
                // cross `core`'s own 60-a-minute limit and lock the installation out of starting
                // sessions at all. The same reasoning the 401 backstop already applies, which this
                // path had not.
                //
                // The role change stays latched across this, so the wait delays it rather than
                // cancelling it.
                $this->nextRenewAttempt = time() + $this->renewRetrySeconds;

                $diagnostic($failure->getMessage());
            }
        }
    }
}
