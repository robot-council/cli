<?php

declare(strict_types=1);

namespace App\Support;

use App\Support\Credentials\Credential;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use RuntimeException;
use Throwable;

/**
 * One agent session against the coordination service, from start to end.
 *
 * **Ending is not optional, and not best-effort.** A session the service still believes is alive
 * holds whatever it claimed: `core` releases a session's tasks and locks when it ends, and waits
 * for the presence sweep otherwise. So a command that starts a session and exits without ending it
 * leaves the fleet holding work nobody is doing, for as long as the stale threshold.
 *
 * That is why `end()` is called from a `finally` rather than after the work: the request failing is
 * exactly the case where leaving a session open costs the most.
 */
final class Session
{
    /**
     * How long the startup diagnostic waits, in seconds.
     *
     * **Short because nothing depends on the answer.** `fleetCanDirect()` decides whether to print
     * one line before the bridge starts serving the protocol, and every failure is answered `null`
     * -- so a slow service costs the harness a delayed startup for a sentence it may not even get.
     * The client's own defaults are `connect_timeout` 10 and `timeout` 30 (measured in
     * `illuminate/http` v13.32.0, `Client/PendingRequest.php` lines 269 and 272), which is a
     * 30-second stall before the first MCP message on a service that accepts a connection and then
     * hangs. This is the only call here that is purely advisory, so it is the only one bounded.
     */
    public const int DESCRIBE_TIMEOUT_SECONDS = 5;

    /**
     * @param  Factory  $http  The HTTP client.
     * @param  string  $service  The service's base URL, with no trailing slash.
     * @param  Credential  $installation  The credential enrollment stored.
     */
    public function __construct(
        private readonly Factory $http,
        private readonly string $service,
        private readonly Credential $installation,
    ) {}

    /**
     * The session's own id, once started.
     */
    private ?int $id = null;

    /**
     * The session token, which is what every call inside the session carries.
     */
    private ?Credential $token = null;

    /**
     * Where the feed stood when this session started.
     *
     * Returned by `core` since #61, so an agent reaches current events in one request rather than
     * walking the whole feed. A one-shot call has nowhere to carry it, and `robot-council/core#86`
     * covers making it recoverable -- it is kept here so the bridge in #5 can use it.
     */
    private ?int $feedCursor = null;

    /**
     * When the current token stops working, as a Unix timestamp.
     *
     * Derived from the `expires_in` the service returned, which is a duration rather than an
     * instant precisely so a machine whose clock is wrong still behaves.
     */
    private ?int $expiresAt = null;

    /**
     * What this session's token is allowed to do.
     *
     * **Re-read on every renewal rather than captured once**, because an admin may narrow a
     * token between them -- `SessionStartController` says so where it sends this: "What the
     * token actually carries, not what the installation looked like when the request arrived."
     * A revocation therefore takes effect at the next renewal rather than at the next process.
     *
     * Empty when the service named none, which is not the same as the service being asked and
     * answering none: an older deployment that omits the field leaves this empty, and every
     * caller reads that as not held, which is the safe direction.
     *
     * @var list<string>
     */
    private array $abilities = [];

    /**
     * Start the session.
     *
     * @param  string|null  $projectId  The repository or workspace, when the caller names one.
     *
     * @throws RuntimeException When the service refuses.
     */
    public function start(?string $projectId = null): void
    {
        $response = $this->http
            ->acceptJson()
            ->asJson()
            ->withToken($this->installation->reveal())
            ->post($this->service.'/robot-council/api/sessions', array_filter([
                'project_id' => $projectId,
            ]));

        if ($response->status() === 401) {
            throw new RuntimeException('This machine is not enrolled, or its credential was revoked. Run `robot-council enroll`.');
        }

        if (! $response->successful()) {
            throw new RuntimeException(sprintf('The service refused to start a session (HTTP %d).', $response->status()));
        }

        $body = $response->json();

        if (! \is_array($body) || ! \is_int($body['session_id'] ?? null) || ! \is_string($body['token'] ?? null)) {
            throw new RuntimeException('The service started a session but did not describe it usably.');
        }

        $this->id = $body['session_id'];
        $this->token = new Credential($body['token']);
        $this->feedCursor = \is_int($body['feed_cursor'] ?? null) ? $body['feed_cursor'] : null;

        $this->expiresAt = \is_int($body['expires_in'] ?? null) ? time() + $body['expires_in'] : null;
        $this->abilities = $this->abilitiesIn($body);
    }

    /**
     * A request carrying this session's token.
     *
     * @throws RuntimeException When the session has not been started.
     */
    public function request(): PendingRequest
    {
        if (! $this->token instanceof Credential) {
            throw new RuntimeException('The session has not been started.');
        }

        return $this->http->acceptJson()->withToken($this->token->reveal());
    }

    /**
     * End the session, releasing whatever it held.
     *
     * **Never throws.** It is called from a `finally`, so an exception here would replace whatever
     * actually went wrong with a failure to clean up after it -- and the caller would lose the real
     * reason. A session that could not be ended is swept by `core` on its presence threshold.
     */
    public function end(): void
    {
        if ($this->id === null || ! $this->token instanceof Credential) {
            return;
        }

        try {
            $this->http
                ->acceptJson()
                ->withToken($this->installation->reveal())
                ->delete($this->service.'/robot-council/api/sessions/'.$this->id);
        } catch (Throwable) {
            // Deliberately swallowed. See above.
        }

        $this->id = null;
        $this->token = null;
    }

    /**
     * Replace this session's token with a fresh one, keeping the same session.
     *
     * The service's `renew` endpoint takes the installation credential, not the session token --
     * deliberately, since the token belonging to a process that just died is the one thing that may
     * no longer work. It also deliberately returns no `feed_cursor`: a renewal must not move where
     * the session is reading.
     *
     * @throws RuntimeException When the session cannot be renewed.
     */
    public function renew(): void
    {
        if ($this->id === null) {
            throw new RuntimeException('The session has not been started.');
        }

        $response = $this->http
            ->acceptJson()
            ->asJson()
            ->withToken($this->installation->reveal())
            ->post($this->service.'/robot-council/api/sessions/'.$this->id.'/renew');

        if (! $response->successful()) {
            throw new RuntimeException(sprintf('The session could not be renewed (HTTP %d).', $response->status()));
        }

        $body = $response->json();

        if (! \is_array($body) || ! \is_string($body['token'] ?? null)) {
            throw new RuntimeException('The service renewed the session but returned no token.');
        }

        $this->token = new Credential($body['token']);

        $this->expiresAt = \is_int($body['expires_in'] ?? null)
            ? time() + $body['expires_in']
            : null;

        // **Renewal is where a narrowed token takes effect.** The service returns what the NEW
        // token carries, which an admin may have reduced since the last one was issued.
        $this->abilities = $this->abilitiesIn($body);
    }

    /**
     * Tell the service this session is still alive.
     *
     * Without it the presence sweep marks an idle session `stale` and then `gone`, and `core`
     * releases whatever it was holding -- so a bridge sitting quietly between tool calls would have
     * its work handed to somebody else.
     */
    public function heartbeat(): void
    {
        if (! $this->token instanceof Credential) {
            return;
        }

        try {
            $this->http
                ->acceptJson()
                ->asJson()
                ->withToken($this->token->reveal())
                ->post($this->service.'/robot-council/api/agent/heartbeat');
        } catch (Throwable) {
            // A missed heartbeat is recoverable -- the next one lands, and the sweep's threshold is
            // minutes rather than seconds. Failing the whole bridge over one would be worse.
        }
    }

    /**
     * Whether the token is close enough to expiry to be worth replacing now.
     *
     * Renewing early rather than on a 401 means an ordinary tool call does not pay for the round
     * trip, and the 401 path stays as the backstop for a token revoked rather than expired.
     *
     * @param  int  $within  How many seconds of remaining life counts as "soon".
     */
    public function expiringWithin(int $within): bool
    {
        return $this->expiresAt !== null && $this->expiresAt - time() <= $within;
    }

    /**
     * Where the feed stood when this session started, when the service said.
     */
    public function feedCursor(): ?int
    {
        return $this->feedCursor;
    }

    /**
     * This session's id, once started.
     */
    public function id(): ?int
    {
        return $this->id;
    }

    /**
     * Whether this session's token carries one ability.
     *
     * **A session that has not started holds nothing**, rather than holding everything. The
     * difference matters for a caller deciding what to show somebody: unknown reads as absent,
     * which is the direction that cannot widen anything by accident.
     *
     * @param  string  $ability  The ability, as the service names it.
     * @return bool Whether the token carries it.
     */
    public function allows(string $ability): bool
    {
        return \in_array($ability, $this->abilities, true);
    }

    /**
     * Whether anything on this fleet can post a directive, or null when the service did not say.
     *
     * **This is a FLEET question, and the session-level one is what makes it worth asking.** A
     * bridge sitting on its sink is waiting for somebody else's directive: posting one needs
     * `coordinator:direct`, enrollment can never request it, and a receive-only session holding
     * none of it is the normal case. So a client that warned on its own abilities would warn on
     * almost every session, which is how a warning stops being read (cli#113).
     *
     * **Asked through `GET agent/session` rather than read off the start response**, because that
     * is where `robot-council/core#159` put the answer. Starting a session and describing one are
     * different questions, and the describing endpoint is the one that reaches across the fleet.
     * One request, once, at startup.
     *
     * **Null rather than false when the answer cannot be had.** An older service that does not send
     * the field, a refused request, or a body that does not parse are all "unknown", and reporting
     * unknown as "nothing will ever arrive" would be a false alarm about the one thing this exists
     * to report truthfully. Only an explicit `false` is a finding.
     *
     * @return bool|null True when some installation can direct, false when none can, null when
     *                   the service did not answer the question.
     */
    public function fleetCanDirect(): ?bool
    {
        // **Observationally equivalent to having no guard at all, and kept anyway.** Without it,
        // `request()` throws `The session has not been started.`, the catch below turns that into
        // the same `null`, and no request is sent either way. So the two mutators that reach that
        // state -- `InstanceOfToTrue`, which skips the branch, and `RemoveEarlyReturn`, which falls
        // through it -- cannot be killed by any input. Every OTHER mutant on these two lines is
        // killed by this file's tests: `IfNegated`, `InstanceOfToFalse` and `RemoveNot` all make a
        // started session answer `null`, and three tests assert it does not. The guard stays
        // because reaching for an exception to express "not started" is worse to read than asking.
        // @pest-mutate-ignore: InstanceOfToTrue, RemoveEarlyReturn
        if (! $this->token instanceof Credential) {
            return null;
        }

        try {
            $response = $this->request()->timeout(self::DESCRIBE_TIMEOUT_SECONDS)->get($this->service.'/robot-council/api/agent/session');
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $body = $response->json();

        if (! \is_array($body) || ! \is_bool($body['fleet_can_direct'] ?? null)) {
            return null;
        }

        return $body['fleet_can_direct'];
    }

    /**
     * The abilities named in a session or renewal response.
     *
     * **A service that does not send the field leaves this empty**, and every caller reads empty as
     * "not held". An older deployment therefore narrows what a client does rather than widening it,
     * which is the safe direction for a field that gates what reaches an agent.
     *
     * @param  array<mixed>  $body  The decoded response.
     * @return list<string> The abilities, or none.
     */
    private function abilitiesIn(array $body): array
    {
        $abilities = $body['abilities'] ?? null;

        if (! \is_array($abilities)) {
            return [];
        }

        // `array_values` keeps the declared `list<string>` rather than whatever keys a service
        // sent, and the filter drops anything that is not a string instead of casting it: a
        // number cast to a string would be an ability nobody granted.
        // `array_values` and `array_filter` are both load-bearing for the DECLARED type rather
        // than for any caller: unwrapping either returns `array<mixed>` or a keyed array where the
        // signature promises `list<string>`, and `composer analyse` refuses both -- measured, one
        // error per unwrap. Killing them with a test would duplicate a check another gate already
        // makes better, which is what the survivor criterion asks to be annotated instead.
        //
        // The marker carries no prose on its own line: v5.0.2 captures the rest of that line and
        // compares it against mutator names, so a trailing explanation suppresses nothing.
        // @pest-mutate-ignore: UnwrapArrayValues, UnwrapArrayFilter
        return array_values(array_filter($abilities, \is_string(...)));
    }
}
