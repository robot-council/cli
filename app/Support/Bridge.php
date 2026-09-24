<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * Forwards MCP messages between a harness's stdio and the coordination service, once it has joined.
 *
 * **Not a pure proxy any more (cli#127).** A bridge given a way to join rather than a session comes
 * up with no session at all: it answers `initialize` itself, lists one tool, `join`, and starts a
 * session only when that tool is called. Opening an editor is not a decision to join a fleet, and a
 * bridge that started a session before it could answer anything made it one.
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
    /**
     * The tool an unjoined bridge offers.
     */
    public const string JOIN_TOOL = 'join';

    /**
     * The roles `join` may ask for, as `robot-council/core`'s `Access\\Role` names them.
     *
     * A copy rather than a lookup, because `core` is not a dependency of this package. The service
     * validates the request too, so a copy that fell behind would refuse a role it knows rather than
     * grant one it does not.
     */
    public const array ROLES = ['build', 'ci', 'coordinator'];

    /**
     * The protocol version answered to a harness that offered none this bridge knows.
     */
    public const string PROTOCOL_VERSION = '2025-11-25';

    /**
     * The protocol versions this bridge answers in, newest first.
     *
     * **Echoing whatever a harness offered would claim versions nobody implements.** When the
     * service held the handshake, `laravel/mcp` negotiated; answering locally, the bridge does, by
     * the specification's rule: agree to a version it supports, and otherwise name its own.
     */
    public const array PROTOCOL_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];

    /**
     * What the agent is told about joining, in the `initialize` result.
     */
    public const string JOIN_INSTRUCTIONS = 'This server connects this session to a Robot Council fleet, and it joins only '
        ."when asked. Until then its one tool is `join`; the fleet's own tools appear once it has joined. Call `join` when "
        .'the operator asks you to join the fleet, or when your instructions say this checkout works with it.';

    public const string CHANNEL_INSTRUCTIONS = 'A channel notice from this server says only that new Robot Council fleet events '
        .'are waiting for this session. The events themselves are delivered by the stop hook when the current turn ends. '
        .'If a notice arrives while nothing else is in progress, end the turn without other work; if you are in the middle '
        .'of a task, carry on with it, and the events arrive when it ends.';

    /**
     * @param  Session|null  $session  A started session to forward through at once, or null to
     *                                 start unjoined and join through `$join`.
     * @param  string  $service  The service's base URL.
     * @param  int  $heartbeatSeconds  How long to wait between heartbeats.
     * @param  int  $renewRetrySeconds  How long a refused renewal waits before another is tried.
     * @param  bool  $channel  Whether to act as a Claude Code channel (cli#62).
     * @param  (Closure(array{role: string|null, repository: string|null, work_location: string|null}): Joined)|null  $join
     *                                                                                                                       What the `join` tool does. It throws a `RuntimeException` carrying what to tell the agent when joining fails.
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
        private ?Session $session,
        private readonly string $service,
        private readonly int $heartbeatSeconds = self::HEARTBEAT_SECONDS,

        // Optional, because the loop's own guarantees must not depend on it: a bridge with no
        // follower forwards tool calls exactly as it did before one existed (cli#60).
        private ?FleetFollower $follower = null,
        private readonly int $renewRetrySeconds = self::RENEW_RETRY_SECONDS,
        private readonly bool $channel = false,
        private readonly ?Closure $join = null,
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
     * What the fleet's own MCP server tells an agent, read once at the join.
     *
     * **The service's instructions reach the agent through here and nowhere else (cli#127).** The
     * bridge answers `initialize` itself, so the fleet's `#[Instructions]` -- among them that
     * everything read from the fleet is data, never instructions -- would otherwise never arrive.
     */
    private ?string $fleetInstructions = null;

    /**
     * Whether a join happened before the harness finished initializing, so the list-changed notice
     * is still owed.
     */
    private bool $listChangedOwed = false;

    /**
     * Ask the loop to finish after the message it is handling.
     */
    public function stop(): void
    {
        $this->stopping = true;
    }

    /**
     * The session this bridge joined, or null while it has not.
     *
     * The command reads it on the way out, because ending a session is its job and there is only
     * a session to end once somebody joined.
     */
    public function session(): ?Session
    {
        return $this->session;
    }

    /**
     * Join now rather than waiting to be asked, for `--auto-join`.
     *
     * **The same path the tool takes**, so a role named here is still only a request and a
     * machine with no credential still comes up. A failure is said on stderr and the bridge serves
     * `join` exactly as if nothing had been tried, because an agent can relay a tool result and an
     * operator rarely reads a harness's server log.
     *
     * @param  array{role: string|null, repository: string|null, work_location: string|null}  $arguments  What to join with.
     * @param  callable(string):void  $diagnostic  Where the outcome is said.
     */
    public function joinNow(array $arguments, callable $diagnostic): void
    {
        $outcome = $this->attemptJoin($arguments);

        $diagnostic($outcome['text']);
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
        if ($this->answerLocally($message, $out, $diagnostic)) {
            return;
        }

        $session = $this->session;

        if (! $session instanceof Session) {
            // Nothing reaches the fleet before a session exists: there is no token to send it with
            return;
        }

        try {
            $response = $this->post($session, $message);

            // One renewal and one retry on a 401, never a loop: a second consecutive refusal means
            // the installation itself is revoked, and retrying forever would hide that
            if ($response === 401) {
                $session->renew();

                $response = $this->post($session, $message);

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

        $this->write($out, $trimmed);
    }

    /**
     * Answer what the bridge answers itself, and say whether it did.
     *
     * **What the bridge owns, whether or not it has joined:** the handshake, `ping`, and the `join`
     * tool. `initialize` is answered here rather than relayed, because an unjoined bridge has no
     * session to relay it with, and one answer for both states is what keeps a harness from seeing
     * two different servers. The fleet's MCP endpoint is stateless -- a `tools/list` with no
     * `initialize` before it is served its first page, measured against the production fleet -- so
     * nothing it relays later depends on the handshake it never saw.
     *
     * **What only an unjoined bridge answers:** `tools/list` with the one tool it has, empty lists
     * for resources and prompts, and an error for anything else a harness asks, since there is no
     * session to ask the fleet with.
     *
     * @param  string  $message  One JSON-RPC message, as the harness wrote it.
     * @param  resource  $out  Where protocol messages go.
     * @param  callable(string):void  $diagnostic  Where anything else goes.
     * @return bool Whether the message was answered here, and so must not be relayed.
     */
    private function answerLocally(string $message, $out, callable $diagnostic): bool
    {
        $decoded = json_decode($message, true);

        if (! \is_array($decoded) || array_is_list($decoded)) {
            // A batch, or something that is not JSON at all: the service's to refuse, once joined,
            // and answered here before then, since a client waiting on a reply would wait forever
            if ($this->session instanceof Session) {
                return false;
            }

            \is_array($decoded)
                ? $this->error($out, null, -32600, 'This bridge does not accept a batch before joining the fleet.')
                : $this->error($out, null, -32700, 'That was not a JSON-RPC message.');

            return true;
        }

        $method = $decoded['method'] ?? null;
        $id = $decoded['id'] ?? null;
        $joined = $this->session instanceof Session;

        if ($method === 'notifications/initialized') {
            $this->initialized = true;

            if ($this->listChangedOwed) {
                $this->listChangedOwed = false;

                $this->notifyListChanged($out);
            }

            return true;
        }

        if ($method === 'initialize') {
            $this->result($out, $id, $this->initializeResult($decoded['params'] ?? null));

            return true;
        }

        if ($method === 'ping') {
            $this->result($out, $id, new stdClass);

            return true;
        }

        if ($method === 'tools/call' && \is_array($decoded['params'] ?? null) && ($decoded['params']['name'] ?? null) === self::JOIN_TOOL) {
            $this->callJoin($out, $id, $decoded['params']['arguments'] ?? null, $diagnostic);

            return true;
        }

        if ($joined) {
            return false;
        }

        match ($method) {
            'tools/list' => $this->result($out, $id, ['tools' => [$this->joinTool()]]),
            'resources/list' => $this->result($out, $id, ['resources' => []]),
            'resources/templates/list' => $this->result($out, $id, ['resourceTemplates' => []]),
            'prompts/list' => $this->result($out, $id, ['prompts' => []]),

            // A notification needs no answer, and one about a session that does not exist has
            // nothing to act on
            default => $id === null ? null : $this->error($out, $id, -32002, 'This bridge has not joined the fleet. Call the `join` tool first.'),
        };

        return true;
    }

    /**
     * The `initialize` result, the same before joining and after.
     *
     * **`tools.listChanged` is what makes joining work.** A harness re-reads the tool list when it
     * is told the list changed only if the server declared that it would say so: Claude Code ignores
     * the notification otherwise, measured headless (#126) and interactive (#130). Cursor re-reads
     * either way, so the declaration costs it nothing.
     *
     * **Resources and prompts are declared** because the fleet serves both once joined, and a harness
     * reads the capabilities once, here, before anybody has joined.
     *
     * @param  mixed  $params  The request's params, for the protocol version the harness offered.
     * @return array<string, mixed> The result.
     */
    private function initializeResult(mixed $params): array
    {
        $offered = \is_array($params) && \is_string($params['protocolVersion'] ?? null) ? $params['protocolVersion'] : null;
        $joined = $this->session instanceof Session;

        $capabilities = [
            'tools' => ['listChanged' => true],
            'resources' => new stdClass,
            'prompts' => new stdClass,
        ];

        // Joining is said only while there is something to join; the fleet's own instructions come
        // first once there is a session, as they did when the service held the handshake
        $instructions = $joined
            ? array_filter([$this->fleetInstructions])
            : [self::JOIN_INSTRUCTIONS];

        // Only where a follower can exist, since a notice announces what it left in the sink
        if ($this->channel && ($this->follower instanceof FleetFollower || $this->join instanceof Closure)) {
            $capabilities['experimental'] = [self::CHANNEL_CAPABILITY => new stdClass];

            $instructions[] = self::CHANNEL_INSTRUCTIONS;
        }

        return [
            // The harness's own version when it is one this bridge knows: `2025-11-25` from both
            // Claude Code and Cursor
            'protocolVersion' => \in_array($offered, self::PROTOCOL_VERSIONS, true) ? $offered : self::PROTOCOL_VERSION,
            'capabilities' => $capabilities,
            'serverInfo' => ['name' => 'Robot Council', 'version' => Version::current()],
            'instructions' => implode("\n\n", $instructions),
        ];
    }

    /**
     * The one tool an unjoined bridge offers.
     *
     * @return array<string, mixed> The tool, as `tools/list` describes it.
     */
    private function joinTool(): array
    {
        return [
            'name' => self::JOIN_TOOL,
            'title' => 'Join the fleet',
            'description' => 'Join the Robot Council fleet this bridge is configured for, so that its coordination tools become '
                .'available. Call it when the operator asks you to join, or when your instructions say this checkout works '
                .'with the fleet. A role other than build is a request an administrator decides, not a grant.',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'role' => [
                        'type' => 'string',
                        'enum' => self::ROLES,
                        'description' => 'The role to ask for. Omit it, or say build, to join as a build agent.',
                    ],
                    'repository' => [
                        'type' => 'string',
                        'description' => 'The GitHub repository this session works in, as owner/name. Read from the checkout when omitted.',
                    ],
                    'work_location' => [
                        'type' => 'string',
                        'description' => 'Which working copy of that repository this is. Read from the checkout when omitted.',
                    ],
                ],
                'additionalProperties' => false,
            ],
        ];
    }

    /**
     * Answer a call to `join`.
     *
     * **A second call is refused and changes nothing.** The session already held stays held: ending
     * it to start another would release its claims and locks for a caller who most likely only
     * forgot they had joined.
     *
     * @param  resource  $out  Where protocol messages go.
     * @param  mixed  $id  The request's id.
     * @param  mixed  $arguments  The tool call's arguments.
     * @param  callable(string):void  $diagnostic  Where anything else goes.
     */
    private function callJoin($out, mixed $id, mixed $arguments, callable $diagnostic): void
    {
        $session = $this->session;

        if ($session instanceof Session) {
            $this->toolResult($out, $id, \sprintf(
                'This bridge has already joined the fleet, as session %s. Nothing was changed; the session it holds stays open.',
                $session->id() ?? 'unknown'
            ), true);

            return;
        }

        $role = \is_array($arguments) ? ($arguments['role'] ?? null) : null;

        if ($role !== null && ! \is_string($role)) {
            $this->toolResult($out, $id, \sprintf('The role must be one of: %s.', implode(', ', self::ROLES)), true);

            return;
        }

        $outcome = $this->attemptJoin([
            'role' => $this->argument($arguments, 'role'),
            'repository' => $this->argument($arguments, 'repository'),
            'work_location' => $this->argument($arguments, 'work_location'),
        ]);

        if ($outcome['joined']) {
            // Before the result, the order #126 measured: the harness re-reads the list at once,
            // and the agent can call a fleet tool in the same turn it joined in. A harness that has
            // not finished initializing is told once it has, rather than not at all.
            $this->initialized ? $this->notifyListChanged($out) : $this->listChangedOwed = true;
        }

        $this->toolResult($out, $id, $outcome['text'], ! $outcome['joined']);

        if (! $outcome['joined']) {
            $diagnostic($outcome['text']);
        }
    }

    /**
     * Join, and say what happened in a sentence an agent can relay.
     *
     * @param  array{role: string|null, repository: string|null, work_location: string|null}  $arguments  What to join with.
     * @return array{joined: bool, text: string} Whether a session now exists, and what to say.
     */
    private function attemptJoin(array $arguments): array
    {
        if (! $this->join instanceof Closure) {
            return ['joined' => false, 'text' => 'This bridge cannot join a fleet: it was started without a way to.'];
        }

        if ($arguments['role'] !== null && ! \in_array($arguments['role'], self::ROLES, true)) {
            return ['joined' => false, 'text' => \sprintf('There is no role called %s. Roles are: %s.', $arguments['role'], implode(', ', self::ROLES))];
        }

        try {
            $joined = ($this->join)($arguments);
        } catch (RuntimeException $runtimeException) {
            return ['joined' => false, 'text' => 'Could not join the fleet: '.$runtimeException->getMessage()];
        } catch (Throwable) {
            // **Anything else as well**, so the bridge stays up: a credential store failing in a
            // way nobody wrapped would otherwise end the loop and leave the call unanswered
            return ['joined' => false, 'text' => 'Could not join the fleet: something failed unexpectedly while joining.'];
        }

        $this->session = $joined->session;
        $this->follower = $joined->follower;

        // The schedules start at the join, since nothing was due while there was no session
        $this->nextHeartbeat = time() + $this->heartbeatSeconds;

        $this->fleetInstructions = $this->readFleetInstructions($joined->session);

        return ['joined' => true, 'text' => $this->fleetInstructions === null
            ? $joined->summary
            : $joined->summary."\n\nThe fleet's instructions:\n".$this->fleetInstructions];
    }

    /**
     * The fleet's own server instructions, asked of it once, or null when it gives none.
     *
     * **An `initialize` sent for its answer, not for its handshake.** The fleet's MCP endpoint is
     * stateless, so this changes nothing there; it is the only way to read what the service tells
     * an agent, now that the harness's own `initialize` is answered here.
     */
    private function readFleetInstructions(Session $session): ?string
    {
        try {
            $response = $this->post($session, (string) json_encode([
                'jsonrpc' => '2.0',
                'id' => 'robot-council-join',
                'method' => 'initialize',
                'params' => [
                    'protocolVersion' => self::PROTOCOL_VERSION,
                    'capabilities' => new stdClass,
                    'clientInfo' => ['name' => 'robot-council bridge', 'version' => Version::current()],
                ],
            ]));
        } catch (Throwable) {
            return null;
        }

        $instructions = \is_string($response) ? data_get(json_decode($response, true), 'result.instructions') : null;

        return \is_string($instructions) && trim($instructions) !== '' ? $instructions : null;
    }

    /**
     * Tell the harness the tool list changed.
     *
     * @param  resource  $out  Where protocol messages go.
     */
    private function notifyListChanged($out): void
    {
        $this->write($out, $this->encode(['jsonrpc' => '2.0', 'method' => 'notifications/tools/list_changed']));
    }

    /**
     * One message as JSON, never an empty line.
     *
     * **A value JSON cannot hold becomes an error, not a blank.** An id of `1e999` decodes to
     * infinity, which `json_encode` refuses; writing its `false` as a string would put a bare
     * newline on the protocol stream.
     *
     * @param  array<string, mixed>  $message  The message.
     */
    private function encode(array $message): string
    {
        $encoded = json_encode($message, JSON_UNESCAPED_SLASHES);

        if ($encoded !== false) {
            return $encoded;
        }

        return (string) json_encode(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32603, 'message' => 'The reply could not be encoded.']]);
    }

    /**
     * One string argument, or null when it is absent, empty, or not a string.
     */
    private function argument(mixed $arguments, string $name): ?string
    {
        $value = \is_array($arguments) ? ($arguments[$name] ?? null) : null;

        return \is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * Write a JSON-RPC result.
     *
     * @param  resource  $out  Where protocol messages go.
     */
    private function result($out, mixed $id, mixed $result): void
    {
        $this->write($out, $this->encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]));
    }

    /**
     * Write a JSON-RPC error.
     *
     * @param  resource  $out  Where protocol messages go.
     */
    private function error($out, mixed $id, int $code, string $message): void
    {
        $this->write($out, $this->encode(['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]]));
    }

    /**
     * Write a tool result carrying one sentence.
     *
     * **`isError` rather than a JSON-RPC error**, so the agent reads the sentence: a protocol error
     * is the harness's to handle, and most report it as a failed call without the text.
     *
     * @param  resource  $out  Where protocol messages go.
     */
    private function toolResult($out, mixed $id, string $text, bool $isError): void
    {
        $this->result($out, $id, ['content' => [['type' => 'text', 'text' => $text]], 'isError' => $isError]);
    }

    /**
     * Write one protocol message.
     *
     * **Every write to the harness goes through here**, which is what keeps stdout to protocol
     * messages and nothing else.
     *
     * @param  resource  $out  Where protocol messages go.
     */
    private function write($out, string $message): void
    {
        fwrite($out, $message."\n");
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

        $this->write($out, $this->encode([
            'jsonrpc' => '2.0',
            'method' => 'notifications/claude/channel',
            'params' => [
                'content' => 'New fleet events are waiting for this session.',

                // Keys must be identifiers and values strings, per the channels reference
                'meta' => ['new' => (string) $this->unannounced],
            ],
        ]));

        $this->unannounced = 0;
    }

    /**
     * Post one message, returning the body, or 401 when the session was refused.
     *
     * @param  string  $message  The JSON-RPC message.
     * @return string|int The response body, or the status when it was 401.
     */
    private function post(Session $session, string $message): string|int
    {
        $response = $session->request()
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
        $session = $this->session;

        // Nothing is due before joining: there is no session to keep alive, feed to read, or token
        // to renew
        if (! $session instanceof Session) {
            return;
        }

        // **A refused heartbeat is how an IDLE bridge learns anything at all.** Every endpoint
        // taking the session token answers 401 once the fleet has ended a session, and an idle
        // bridge makes no tool calls and may be an hour from its scheduled renewal -- so without
        // this it would sit there, failing quietly, until the token aged out. What the refusal
        // MEANS is settled by the renewal below, which is the only endpoint that still answers.
        if (time() >= $this->nextHeartbeat) {
            $refused = ! $session->heartbeat();

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

        if (($this->roleChanged || $this->renewalDue || $session->expiringWithin(self::RENEW_WITHIN_SECONDS)) && time() >= $this->nextRenewAttempt) {
            try {
                $session->renew();

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
