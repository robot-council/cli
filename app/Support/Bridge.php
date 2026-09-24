<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;
use stdClass;
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
     * The capability that makes Claude Code treat this server as a channel.
     *
     * Declared by the bridge rather than by the service, because the notice originates here: the
     * bridge is what follows the feed, and the service's MCP endpoint cannot write to this stdio
     * (cli#62).
     */
    public const string CHANNEL_CAPABILITY = 'claude/channel';

    /**
     * What the agent is told a channel notice means.
     *
     * **Trusted where the notice is not, and that asymmetry is why the notice carries no events.**
     * Measured for cli#117: a woken turn treats channel content as external data and declines an
     * instruction inside it, while server instructions arrive through configuration the operator
     * chose. So the notice only says that something is waiting, and this says what to do about it.
     * Ending the turn is the whole of it, because the stop hook delivers the events at the turn
     * boundary through the path an agent already acts on.
     *
     * **Conditional on having nothing else in progress.** A notice can arrive while the agent is
     * working, and an unconditional "end the turn" would abandon a task halfway for news the stop
     * hook delivers at that task's natural end anyway.
     */
    public const string CHANNEL_INSTRUCTIONS = 'A channel notice from this server says only that new Robot Council fleet events '
        .'are waiting for this session. The events themselves are delivered by the stop hook when the current turn ends. '
        .'If a notice arrives while nothing else is in progress, end the turn without other work; if you are in the middle '
        .'of a task, carry on with it, and the events arrive when it ends.';

    /**
     * @param  Session  $session  The started session this bridge forwards through.
     * @param  string  $service  The service's base URL.
     * @param  int  $heartbeatSeconds  How long to wait between heartbeats.
     * @param  int  $renewRetrySeconds  How long a refused renewal waits before another is tried.
     * @param  bool  $channel  Whether to act as a Claude Code channel (cli#62).
     *
     * Both intervals are parameters rather than only constants because otherwise nothing can show
     * what they do: the schedule is read from `time()` inside a loop that blocks on
     * `stream_select`, so a test cannot advance the clock from outside it and would have to sit
     * through a real minute -- or, for the retry, half a real one. Passing 0 makes the next pass
     * due, which is what the tests do. Nothing in this application passes anything but the
     * defaults.
     *
     * `$channel` is off unless the caller turns it on, because it is one harness's extension: only
     * Claude Code reads `claude/channel`, and how another harness's client treats an unknown server
     * notification has not been measured.
     */
    public function __construct(
        private readonly Session $session,
        private readonly string $service,
        private readonly int $heartbeatSeconds = self::HEARTBEAT_SECONDS,

        // Optional, because the loop's own guarantees must not depend on it: a bridge with no
        // follower forwards tool calls exactly as it did before one existed (cli#60).
        private readonly ?FleetFollower $follower = null,
        private readonly int $renewRetrySeconds = self::RENEW_RETRY_SECONDS,
        private readonly bool $channel = false,
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
     * Whether something asked for a renewal outside the ordinary schedule.
     *
     * Set when a heartbeat is refused, which is the earliest routine sign that the session token is
     * no longer honored. The renewal that follows is what distinguishes the reasons: a new token
     * means it was merely stale or revoked and the bridge carries on, and a `409` means the fleet
     * has ended the session and there is nothing to carry on with.
     */
    private bool $renewalDue = false;

    /**
     * Whether a signal has asked the loop to stop.
     */
    private bool $stopping = false;

    /**
     * The ids of `initialize` requests still waiting for their result, keyed by their JSON form.
     *
     * JSON-RPC allows a string or a number, and `1` and `"1"` are different ids, so the key keeps
     * the type rather than letting PHP's array keys fold them together.
     *
     * @var array<string, true>
     */
    private array $initializing = [];

    /**
     * Whether the harness has said it finished initializing, after which a notice may be sent.
     *
     * A notification written before `notifications/initialized` goes into a transport the harness
     * is not yet reading, which is indistinguishable from a channel that never registered.
     */
    private bool $initialized = false;

    /**
     * Events left in the sink that no notice has announced yet.
     *
     * **Kept rather than dropped while the harness is still initializing.** The first pass of the
     * loop forwards `initialize` and then reads the feed before the harness can have answered with
     * `notifications/initialized`, so a batch written then would otherwise never be announced.
     */
    private int $unannounced = 0;

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

            $this->periodic($out, $diagnostic);
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
        $initializing = $this->observe($message);

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
        } catch (SessionHasGone $gone) {
            // **The busy bridge's path to the same news.** A tool call is refused, the renewal above
            // answers `409`, and there is nothing to retry: this session's claims and locks are
            // already released. Reported once and the loop ends, rather than every subsequent call
            // repeating it.
            $diagnostic($gone->getMessage());

            $this->stop();
        } catch (Throwable $throwable) {
            // To stderr, never to stdout: a harness parsing stdout would read a diagnostic as a
            // malformed protocol message rather than as an error
            $diagnostic($throwable->getMessage());
        } finally {
            // **Whatever became of the request, its id stops being an `initialize`.** A result
            // that was relayed has already cleared it; one that failed, or was not a protocol
            // message, would otherwise leave it waiting for a later response that reuses the id.
            if ($initializing !== null) {
                unset($this->initializing[$initializing]);
            }
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

        // One of the two things this class writes to `$out`; the other is `announce()`
        fwrite($out, $this->declareChannel($trimmed)."\n");
    }

    /**
     * Note what the harness sent that the channel depends on.
     *
     * @param  string  $message  One JSON-RPC message, as the harness wrote it.
     * @return string|null The key an `initialize` request was recorded under, or null.
     */
    private function observe(string $message): ?string
    {
        // Both methods this looks for contain the word, and a tool call can run to hundreds of
        // kilobytes, so the rest are not decoded a second time for nothing
        if (! $this->channel || ! str_contains($message, 'initialize')) {
            return null;
        }

        $decoded = json_decode($message, true);

        if (! \is_array($decoded)) {
            return null;
        }

        $method = $decoded['method'] ?? null;

        if ($method === 'notifications/initialized') {
            $this->initialized = true;
        }

        if ($method !== 'initialize' || ! isset($decoded['id'])) {
            return null;
        }

        $key = (string) json_encode($decoded['id']);

        $this->initializing[$key] = true;

        return $key;
    }

    /**
     * Add the channel capability and its instructions to an `initialize` result, and leave anything
     * else exactly as the service sent it.
     *
     * **Decoded as objects, never as arrays.** The service's capabilities hold empty objects such as
     * `"tools":{}`, and an associative decode turns those into empty arrays that re-encode as `[]` --
     * a different value, in the one message a harness reads to decide what this server can do.
     *
     * **Only when there is a follower.** A notice announces what the follower left in the sink, so a
     * bridge without one would declare a channel that can never send anything.
     *
     * **The service's bytes when the rewrite cannot be encoded**, rather than no answer at all: a
     * harness that never receives its `initialize` result does not start the server, and a channel
     * is not worth that. The round trip otherwise changes only how some numbers are spelled.
     *
     * @param  string  $response  One protocol message, already known to parse.
     * @return string The message to write.
     */
    private function declareChannel(string $response): string
    {
        if (! $this->channel || ! $this->follower instanceof FleetFollower || $this->initializing === []) {
            return $response;
        }

        $decoded = json_decode($response);

        if (! $decoded instanceof stdClass || ! isset($decoded->id)) {
            return $response;
        }

        $key = (string) json_encode($decoded->id);

        if (! isset($this->initializing[$key])) {
            return $response;
        }

        unset($this->initializing[$key]);

        $result = $decoded->result ?? null;

        // An error, or a result with no capabilities to add to: relayed untouched
        if (! $result instanceof stdClass || ! ($result->capabilities ?? null) instanceof stdClass) {
            return $response;
        }

        $capabilities = $result->capabilities;

        $experimental = $capabilities->experimental ?? null;

        if (! $experimental instanceof stdClass) {
            $experimental = new stdClass;
        }

        $experimental->{self::CHANNEL_CAPABILITY} = new stdClass;
        $capabilities->experimental = $experimental;

        // Added to what the service says rather than replacing it
        $existing = $result->instructions ?? null;

        $result->instructions = \is_string($existing) && trim($existing) !== ''
            ? $existing."\n\n".self::CHANNEL_INSTRUCTIONS
            : self::CHANNEL_INSTRUCTIONS;

        $encoded = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

        return $encoded === false ? $response : $encoded;
    }

    /**
     * Tell the harness that the follower has left new events in the sink.
     *
     * **Never the events.** The sink stays the one record of what was sent and the stop hook the
     * one path that delivers it, so a notice is only a reason for an idle agent to have a turn to
     * end (cli#62). A harness that never enabled channels drops the notification silently, as the
     * channels reference documents, and loses nothing, because the sink was written either way.
     *
     * **It says "new", not how many are waiting.** `new` in the meta is how many this notice
     * covers; what the sink holds is not the bridge's to know, since the stop hook drains it.
     *
     * @param  resource  $out  Where protocol messages go.
     */
    private function announce($out): void
    {
        if (! $this->channel) {
            return;
        }

        $this->unannounced += $this->follower?->delivered() ?? 0;

        if ($this->unannounced === 0 || ! $this->initialized) {
            return;
        }

        fwrite($out, json_encode([
            'jsonrpc' => '2.0',
            'method' => 'notifications/claude/channel',
            'params' => [
                'content' => 'New fleet events are waiting for this session.',

                // Keys must be identifiers and values strings, per the channels reference
                'meta' => ['new' => (string) $this->unannounced],
            ],
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");

        $this->unannounced = 0;
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
     * Heartbeat, follow the feed, and renew, on their own schedules.
     *
     * @param  resource  $out  Where protocol messages go, for a channel notice.
     * @param  callable(string):void  $diagnostic  Where anything else goes.
     */
    private function periodic($out, callable $diagnostic): void
    {
        // **A refused heartbeat is how an IDLE bridge learns anything at all.** Every endpoint
        // taking the session token answers 401 once the fleet has ended a session, and an idle
        // bridge makes no tool calls and may be an hour from its scheduled renewal -- so without
        // this it would sit there, failing quietly, until the token aged out. What the refusal
        // MEANS is settled by the renewal below, which is the only endpoint that still answers.
        if (time() >= $this->nextHeartbeat) {
            $refused = ! $this->session->heartbeat();

            $this->nextHeartbeat = time() + $this->heartbeatSeconds;

            if ($refused) {
                $this->renewalDue = true;
            }
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

        $this->announce($out);

        // **The feed's 401 asks the same question the heartbeat's does**, and the renewal below is
        // what answers it. Without this a bridge whose session had been ended learned nothing from
        // the feed except a widening backoff, and an idle one with an hour of token life left had
        // only the heartbeat to fall back on (#169).
        if ($this->follower?->sessionWasRefused() === true) {
            $this->renewalDue = true;
        }

        if (($this->roleChanged || $this->renewalDue || $this->session->expiringWithin(self::RENEW_WITHIN_SECONDS)) && time() >= $this->nextRenewAttempt) {
            try {
                $this->session->renew();

                $this->renewalDue = false;

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
            } catch (SessionHasGone $gone) {
                // **Terminal, so the loop ends here rather than backing off into a retry.** Nothing
                // this bridge sends afterwards can be acted on: the session's claims and locks are
                // already released and its token is already refused. Saying so once and stopping is
                // the honest end (#157), and it replaces the message an operator got instead --
                // `The session could not be renewed (HTTP 409).`, which names a status code rather
                // than a cause.
                $diagnostic($gone->getMessage());

                $this->stop();

                return;
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
