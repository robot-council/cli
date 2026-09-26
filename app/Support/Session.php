<?php

declare(strict_types=1);

namespace App\Support;

use App\Support\Credentials\Credential;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
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
     * The capacity in effect, as the service reported it at start, or null (#302).
     */
    private ?int $capacity = null;

    /**
     * The capacity this session declared, as the service reported it at start, or null (#302).
     */
    private ?int $declaredCapacity = null;

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
     * **The three travel together, and the service does not fill one in from another.** It splits
     * a `project_id` into a repository and a work location only when the client names *neither*,
     * so sending a repository and omitting a location leaves the location null rather than split
     * out of the label. Whatever is known is therefore sent, and what is not known is omitted.
     *
     * `project_id` keeps being sent while the service still stores it. Retiring it is the epic's
     * own final slice, not this one.
     *
     * **The platform rides along, read rather than configured** (#266): `Platform::report()`, on
     * every start, and dropped for a retry if the service refuses it.
     *
     * **An ephemeral session is one the fleet is not told about** (#298): core omits its join and
     * end events and leaves it out of the session list (`robot-council/core#424`). The flag is sent
     * only when set, so every other session's request is byte-for-byte what it was, and a service
     * that does not know the field drops it and starts an ordinary session.
     *
     * @param  string|null  $projectId  The old single label, when the caller names one.
     * @param  string|null  $repository  The GitHub repository, as `owner/name`.
     * @param  string|null  $workLocation  Which working copy of it this is.
     * @param  bool  $ephemeral  Whether to keep this session out of the feed and the session list.
     * @param  int|null  $capacity  How many tasks this session declares it will hold at once, or
     *                              null to leave the service's default of 1 (#302).
     *
     * @throws RuntimeException When the service refuses.
     */
    public function start(?string $projectId = null, ?string $repository = null, ?string $workLocation = null, bool $ephemeral = false, ?int $capacity = null): void
    {
        // **Filtered on null rather than on falsiness.** A bare `array_filter` also drops `'0'`,
        // and `0` is a legal work location -- a worktree may be called that -- so a directory named
        // `0` would have silently reported no location at all.
        $identity = array_filter([
            'project_id' => $projectId,
            'repository' => $repository,
            'work_location' => $workLocation,
        ], static fn (?string $value): bool => $value !== null);

        // Part of the identity rather than beside the platform, so the retry below keeps it
        if ($ephemeral) {
            $identity['ephemeral'] = true;
        }

        // **Sent only when declared** (#302), so every other start is byte-for-byte what it was.
        // `robot-council/core` v0.7.0 takes 1 to 16, stores more as 16, and refuses 0 or less
        if ($capacity !== null) {
            $identity['capacity'] = $capacity;
        }

        $response = $this->post([...$identity, 'platform' => Platform::report()]);

        // **The platform must never be the reason a session fails to start** (#266). A service
        // without the field ignores it (`robot-council/core` before #351 validates only the keys it
        // lists), but one that knows it refuses a value outside its own bounds with a 422 naming
        // the field -- so a refusal that names `platform` is answered by starting without it.
        if ($response->status() === 422 && $this->refusedPlatform($response->json())) {
            $response = $this->post($identity);
        }

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

        // What the service holds this session to, and what was declared, which differ when the
        // seat's cap applied. Null from a service that predates capacity
        $this->capacity = \is_int($body['capacity'] ?? null) ? $body['capacity'] : null;
        $this->declaredCapacity = \is_int($body['declared_capacity'] ?? null) ? $body['declared_capacity'] : null;
    }

    /**
     * How many tasks the service lets this session hold at once, as the start reported it (#302).
     */
    public function capacity(): ?int
    {
        return $this->capacity;
    }

    /**
     * How many tasks this session declared it would hold, as the start reported it (#302).
     */
    public function declaredCapacity(): ?int
    {
        return $this->declaredCapacity;
    }

    /**
     * Ask the service to start a session with this body.
     *
     * @param  array<string, mixed>  $body  What to send.
     */
    private function post(array $body): Response
    {
        return $this->http
            ->acceptJson()
            ->asJson()
            ->withToken($this->installation->reveal())
            ->post($this->service.'/robot-council/api/sessions', $body);
    }

    /**
     * Whether a 422's body names the `platform` field among its errors.
     *
     * @param  mixed  $body  The decoded response.
     */
    private function refusedPlatform(mixed $body): bool
    {
        $errors = \is_array($body) ? ($body['errors'] ?? null) : null;

        if (! \is_array($errors)) {
            return false;
        }

        return array_any(array_keys($errors), fn (int|string $field): bool => \is_string($field) && ($field === 'platform' || str_starts_with($field, 'platform.')));
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

        // **409 is the one terminal answer, and the only in-band way a client learns it.** Core's
        // `SessionRenewController` documents it as exactly and only the gone case, and this endpoint
        // takes the installation credential rather than the session token -- so it still answers for
        // a session whose tokens have been deleted, where every other endpoint is a 401. Collapsing
        // it into the message below left the bridge unable to tell a bad minute from an ended
        // session (#165).
        if ($response->status() === 409) {
            throw new SessionHasGone;
        }

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
     * Tell the service this session is still alive, and say whether it was accepted.
     *
     * @return bool False when the service refused the heartbeat, which for a session token means it
     *              is no longer honored. True when it landed, and also when it could not be sent at
     *              all -- an unsent heartbeat is not evidence about the session.
     *
     * Without it the presence sweep marks an idle session `stale` and then `gone`, and `core`
     * releases whatever it was holding -- so a bridge sitting quietly between tool calls would have
     * its work handed to somebody else.
     */
    public function heartbeat(): bool
    {
        if (! $this->token instanceof Credential) {
            return false;
        }

        try {
            $response = $this->http
                ->acceptJson()
                ->asJson()
                ->withToken($this->token->reveal())
                ->post($this->service.'/robot-council/api/agent/heartbeat');
        } catch (Throwable) {
            // A missed heartbeat is recoverable -- the next one lands, and the sweep's threshold is
            // minutes rather than seconds. Failing the whole bridge over one would be worse.
            //
            // Answered as "not refused", because a heartbeat that never arrived says nothing about
            // whether this session still exists, and the caller must not read it as though it did.
            return true;
        }

        // **A refusal is reported rather than swallowed, and it is the only way an IDLE bridge
        // learns anything.** A 401 does not throw in this client, so the catch above never saw one
        // and the caller could not tell a delivered heartbeat from a rejected one. For a session
        // the fleet has ended, every endpoint that takes the session token answers 401 -- so this
        // is the earliest routine signal that something is wrong, and what it means is settled by
        // a renewal, which answers 409 for exactly that case (#165).
        return $response->status() !== 401;
    }

    /**
     * Tell the service the bridge's watcher is still watching, and say what it answered.
     *
     * **Apart from `heartbeat()`, because it answers a different question** (#264). Every request
     * an agent makes refreshes the session's contact, so a lane whose bridge watcher died while its
     * agent kept calling tools would read as watched. Core records this one alone, in its own
     * column, and the lane board's `Watcher` reads it (`robot-council/core#337`).
     *
     * @return int The HTTP status, or 0 when nothing came back. `404` is a service without the
     *             route, which is every release before the one carrying `robot-council/core#350`.
     */
    public function watcherHeartbeat(): int
    {
        if (! $this->token instanceof Credential) {
            return 0;
        }

        try {
            return $this->http
                ->acceptJson()
                ->asJson()
                ->withToken($this->token->reveal())
                ->post($this->service.'/robot-council/api/agent/watcher')
                ->status();
        } catch (Throwable) {
            return 0;
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
     * Ask for a role, which an administrator decides.
     *
     * **A request, never a grant.** `POST agent/role` records what this session asked to be and
     * answers `202` while an administrator has yet to decide, or `200` when there was nothing to
     * decide -- the session already holds that role. Nothing about the token changes here, so a
     * session that asked to coordinate goes on holding `build`'s abilities until it is approved and
     * renewed (cli#127).
     *
     * **A separate call from `start()`, because that is where `core` put it.** Session start takes
     * no role, and a field it does not validate is dropped rather than refused -- so sending one there
     * would start a `build` session and report nothing wrong.
     *
     * @param  string  $role  The role wanted, as the service names it.
     * @return array{pending: bool, role: string|null} Whether an administrator now has to decide,
     *                                                 and the role this session holds meanwhile.
     *
     * @throws RuntimeException When the session has not started, or the service refuses the request.
     */
    public function requestRole(string $role): array
    {
        $response = $this->request()
            ->asJson()
            ->post($this->service.'/robot-council/api/agent/role', ['role' => $role]);

        if (! $response->successful()) {
            $body = $response->json();
            $reason = \is_array($body) && \is_string($body['message'] ?? null) ? ' '.$body['message'] : '';

            throw new RuntimeException(\sprintf('The service refused the role request (HTTP %d).%s', $response->status(), $reason));
        }

        $body = $response->json();

        return [
            'pending' => \is_array($body) && ($body['pending'] ?? null) === true,
            'role' => \is_array($body) && \is_string($body['role'] ?? null) ? $body['role'] : null,
        ];
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
