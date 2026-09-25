<?php

declare(strict_types=1);

namespace App\Support;

use Throwable;

/**
 * Reads the fleet's change feed and leaves what concerns this session where a hook can find it.
 *
 * **Every path into the fleet is agent-initiated, so an agent that is not running a turn learns
 * nothing.** A directive, a hand-back, or a lock being force-released sits in the feed until
 * something causes that agent to read it, and the interval is unbounded rather than slow, because
 * nothing schedules the next read (cli#59, cli#60).
 *
 * It lives in the bridge because the bridge already has everything: a loop that ticks every five
 * seconds, the credential, the session, and the feed cursor the service handed back when the
 * session started. A separate long-running command would need a second credential presentation and
 * would start a second session, which the fleet would list as an extra agent.
 *
 * **What it does not do is wake anybody.** It makes the news available at a turn boundary; the
 * harness's stop hook is what hands it to the agent (cli#61). Where the harness supports a channel,
 * the bridge announces each batch so that an idle agent has a turn to end (cli#62).
 */
final class FleetFollower
{
    /**
     * How long to wait between reads of the feed, in seconds.
     *
     * Six requests a minute against the 120 a minute `robot-council.rate_limits.agent_per_session`
     * allows, with the bridge's own heartbeat taking one more. The interval is not latency: nothing
     * acts on what this finds until the agent finishes a turn.
     */
    public const int POLL_SECONDS = 10;

    /**
     * How long to wait before reading again after a failure, and the ceiling it backs off to.
     *
     * The same shape the bridge's token renewal uses, and for the reason that one records: retrying
     * on every pass was measured at ten attempts a minute while idle, which can cross the service's
     * own rate limit and lock an installation out of the thing it was trying to do.
     */
    public const int BACKOFF_SECONDS = 30;

    public const int MAX_BACKOFF_SECONDS = 300;

    /**
     * The event types that concern a session whoever they name -- unless they name somebody.
     *
     * **A directive with `targets` concerns only the sessions it names** (#269).
     * `robot-council/core#139` let a directive record which sessions are expected to act, and this
     * list predates it: every directive went into every sink, and on Claude Code every sink write
     * wakes an idle session (#197), so a coordinator speaking to one session interrupted all of
     * them. An unnamed session still reads the directive through `events_read` at its own next
     * turn; it is only not woken for it. A directive without `targets` is for the fleet, as before.
     */
    private const array ALWAYS = ['directive'];

    /**
     * The ability that also makes the fleet's own activity concern a session.
     *
     * Named here rather than imported, because this repository has no ability enum: the service
     * owns that list, and a copy of it here would be a second place to keep right.
     */
    private const string COORDINATOR = 'coordinator:direct';

    /**
     * What a coordinating session hears about beyond its own work.
     *
     * **Narration is deliberately absent.** It is the one type `robot-council/core` restricts, and
     * widening it here would put another developer's session's words into an agent with shell
     * access on the strength of a client-side check. The types below are state changes the service
     * already serves to every session; this only stops discarding them.
     */
    private const array COORDINATOR_HEARS = [
        'session.stale', 'session.gone', 'session.resumed',

        // **Who may direct, and who was refused when they asked** (#148). #115 decided a
        // coordinating session hears about the other sessions on the reasoning that "the role
        // which needs to watch is the role which needs to direct", and a role is more squarely
        // that than a lock being acquired, which was already on this list.
        //
        // Two things a coordinator could not see: another session promoted to `coordinator`, so
        // that two direct work while neither knows of the other; and a session refused a role,
        // recorded against the asking session, so a coordinator watches it behave as though a
        // request were still pending.
        //
        // **Narration's exemption does not extend to them.** These carry no free text --
        // `Support\RoleRequests` writes role names from a backed enum plus `how` -- so the
        // reasoning above for keeping narration out does not apply. And the service already
        // serves both to every session: `Support\FleetFeed::visibleWithin()` restricts narration
        // alone, so they were being discarded here rather than withheld there.
        'session.role_changed', 'session.role_requested',

        'task.created', 'task.claimed', 'task.started', 'task.blocked',
        'task.completed', 'task.failed', 'task.released', 'task.reassigned', 'task.cancelled',
        'lock.acquired', 'lock.released', 'lock.taken_over', 'lock.force_released',
    ];

    /**
     * The presence types that name a session rather than being caused by it.
     *
     * **`session.resumed` is deliberately absent.** A session resumes by contacting the service, so
     * it IS the actor there, and hearing about its own resumption would be telling an agent what it
     * just did. Only the sweep writes `session.stale`, and the sweep is not a session.
     *
     * **`session.gone` is absent too, because a session can never read its own (#175).** Core's
     * `Support\SessionPresence::goesNow()` flips the status, records the event and deletes the
     * session's tokens in one transaction, and `Http\Middleware\EnsureAgentSession` refuses a gone
     * session outright -- so from the instant the event exists, this session's feed read is a 401,
     * with no token left to retry it. Reaching it would take core reversing both. A gone session is
     * learned from the renewal's `409` instead (`SessionHasGone`), the one endpoint that still
     * answers. Another session's `session.gone` is readable, and reaches a coordinator through
     * `COORDINATOR_HEARS`.
     */
    private const array OWN_PRESENCE = ['session.stale'];

    /**
     * Where the feed has been read to.
     */
    private ?int $cursor;

    /**
     * When the feed may next be read, as a Unix timestamp.
     */
    private int $nextPoll = 0;

    /**
     * How long the next failure waits, in seconds.
     */
    private int $backoff;

    /**
     * Whether the last feed read was refused outright.
     *
     * **Describes the most recent TICK rather than the most recent read**, so a tick that sat out
     * the backoff answers false rather than repeating the last refusal. A 401 says the session
     * token is not honored; what that MEANS is the renewal's to answer, exactly as it is for a
     * refused heartbeat.
     */
    private bool $refused = false;

    /**
     * How many reads in a row have been refused.
     *
     * **The guard on suppressing a 401 at all.** Every branch of `EnsureAgentSession` that answers
     * 401 is either repaired by the renewal or reported by it -- a session the fleet ended gets a
     * `409`, and an unusable installation refuses the renewal too, which `Session::renew()` reports.
     * The one shape neither covers is a feed that keeps refusing while the renewal keeps
     * succeeding, which a proxy in front of the service can produce. Silence there would be a
     * bridge that never reads the feed again and never says so, so the first refusal is quiet and
     * the rest are not.
     */
    private int $refusals = 0;

    /**
     * How many events the last tick left in the sink.
     *
     * **Per tick, like `$refused`**, so a caller asking after every tick is told about each batch
     * once. A tick that sat out the poll interval, found nothing, or failed to write answers zero:
     * only events actually waiting for the stop hook are worth waking an agent for (cli#62).
     */
    private int $delivered = 0;

    /**
     * The tasks this session is holding, as far as the feed has said.
     *
     * **Derived from the feed rather than looked up**, because `task.reassigned` and `task.cancelled`
     * record the task, the new status and the new assignee -- and not whom the task was taken from.
     * The set is complete anyway: a session can only hold a task it claimed or was handed during its
     * own life, and the cursor starts at that session's own enrollment, so both arrive here.
     *
     * @var array<int, true>
     */
    private array $held = [];

    /**
     * @param  Session  $session  The started session whose feed this reads.
     * @param  string  $service  The service's base URL.
     * @param  PendingEvents  $pending  Where matching events are left.
     * @param  int  $pollSeconds  How long to wait between reads.
     */
    public function __construct(
        private readonly Session $session,
        private readonly string $service,
        private readonly PendingEvents $pending,
        private readonly int $pollSeconds = self::POLL_SECONDS,

        // A parameter for the same reason `$pollSeconds` is one: the schedule is read from `time()`
        // inside a loop a test cannot advance, so nothing could show what a SECOND consecutive
        // refusal does without sitting through `BACKOFF_SECONDS`. Nothing in the application passes
        // anything but the default, and the doubling above is deliberately unfloored so that a zero
        // passed here stays zero -- a floor would make the second failure wait, which is the exact
        // thing the parameter exists to avoid.
        private readonly int $backoffSeconds = self::BACKOFF_SECONDS
    ) {
        $this->backoff = $backoffSeconds;

        $this->cursor = $session->feedCursor();
    }

    /**
     * The sink this follower leaves events in.
     *
     * **Exposed so the bridge can leave a record of its OWN, which is not a fleet event.** The sink
     * and the follower are created together by the join and have the same lifetime, so the follower
     * is the object that can hand it over without a second path having to build one from the same
     * three identifiers and risk keying it differently.
     */
    public function pending(): PendingEvents
    {
        return $this->pending;
    }

    /**
     * Whether the last feed read was refused, which is a question rather than an answer.
     *
     * The caller turns it into a renewal, and the renewal distinguishes the reasons: a new token
     * means the old one was merely revoked or expired, and a `409` means the fleet has ended this
     * session. Symmetrical with `Session::heartbeat()` answering false (#165, #169).
     */
    public function sessionWasRefused(): bool
    {
        return $this->refused;
    }

    /**
     * How many events the last tick left in the sink, which is what a channel notice announces.
     */
    public function delivered(): int
    {
        return $this->delivered;
    }

    /**
     * What the sink holds now, as a fingerprint, or null when nothing is waiting.
     *
     * **Read, never written.** `peek()` takes a shared lock and leaves the sink as it was, so the
     * bridge can ask whether its last notice was acted on without racing a stop hook's drain
     * (cli#230). A fingerprint rather than the events, because the question is only whether the
     * sink changed since then, and the events are the stop hook's to deliver.
     */
    public function waiting(): ?string
    {
        $events = $this->pending->peek();

        return $events === [] ? null : hash('sha256', (string) json_encode($events));
    }

    /**
     * Read the feed if it is due, and leave anything that concerns this session.
     *
     * **Never throws and never writes to stdout.** A bridge whose follower failed must go on
     * forwarding tool calls: the feed is an extra, and a fleet that cannot be read is not a reason
     * to stop answering the agent in front of you.
     *
     * **Answers whether this session's own role changed**, which the caller acts on by renewing.
     * The abilities a session believes it holds are the ones it was handed at start, and
     * `concerns()` gates on them -- so a session promoted to `coordinator` goes on discarding the
     * very events it was promoted to hear, for as long as an hour, while the service would let it
     * post a directive. The client and the service disagree, and the client is the one deciding
     * what reaches the agent.
     *
     * @param  callable(string):void  $diagnostic  Where anything that is not a protocol message goes.
     * @return bool Whether this session's role changed and its token should be renewed now.
     */
    public function tick(callable $diagnostic): bool
    {
        // **Cleared per tick, not per read, and the difference is a renewal storm.** A refused read
        // backs the follower off for `BACKOFF_SECONDS`, so the ticks that follow return below
        // without reaching the service -- and a flag cleared only by a read would stay true for the
        // whole backoff, asking the caller for a renewal on every pass. Measured while building
        // #169: three renewals where one was owed, and the mutation that should have caught it
        // could not, because the flag was already sticky for the window under test.
        $this->refused = false;
        $this->delivered = 0;

        if ($this->cursor === null || time() < $this->nextPoll) {
            return false;
        }

        try {
            $page = $this->read();
        } catch (Throwable $throwable) {
            $this->nextPoll = time() + $this->backoff;

            $this->backoff = min($this->backoff * 2, self::MAX_BACKOFF_SECONDS);

            // **A 401 is not a feed problem, so it is recorded rather than described.** It says this
            // session's token is no longer honored, which the feed cannot explain and the renewal
            // can: a token merely revoked and reissued renews fine, and a session the fleet has
            // ended answers `409`. Measured before this, a swept session was told its feed was
            // unreadable one line before being told why -- the symptom ahead of the cause (#169).
            $this->refused = $throwable->getCode() === 401;
            $this->refusals = $this->refused ? $this->refusals + 1 : 0;

            if (! $this->refused) {
                $diagnostic('Could not read the fleet feed: '.$throwable->getMessage());
            } elseif ($this->refusals > 1) {
                // **Only what this class saw.** An earlier wording said "renewing has not fixed
                // it" and "Tool calls are unaffected", and both were claims it had no standing to
                // make. `FleetFollower` neither performs nor observes a renewal -- the caller may
                // not have attempted one, since `Bridge` gates it behind `nextRenewAttempt` -- and
                // tool calls are **not** unaffected: `robot-council/core` registers its MCP
                // endpoint behind the same `EnsureAgentSession` guard as `GET events`, taking the
                // same session token, so any 401 about that token refuses tool calls too. Telling
                // an operator their tool calls were fine while every one of them failed is the
                // defect this whole ticket exists to remove, reintroduced by its own fix.
                //
                // The renewal's own failure line, which `Bridge` already prints, supplies the rest.
                $diagnostic(sprintf(
                    'The fleet feed has refused this session %d times in a row.',
                    $this->refusals
                ));
            }

            return false;
        }

        $this->backoff = $this->backoffSeconds;
        $this->refusals = 0;
        $this->nextPoll = time() + $this->pollSeconds;

        // The cursor the service returns, not the last event's id: a page can be short or empty
        // because of the visibility rule, and a reader still has to make progress.
        $this->cursor = $page['cursor'] ?? $this->cursor;

        $concerning = [];
        $roleChanged = false;

        foreach ($page['events'] as $event) {
            // **Its own role, which `concerns()` filters out with everything else this session did.**
            // That filter is right for the sink -- an agent does not need to be told what it just
            // did -- and wrong here: a role is decided by an administrator rather than by this
            // session, so it arrives as an event about this session that this session did not cause,
            // and it is the one thing about itself the bridge has to act on.
            if ($this->actor($event) === $this->session->id() && $this->noteOwnRole($event, $diagnostic)) {
                $roleChanged = true;

                // **And nothing below it in this page is decided here.** Those events happened
                // after the role did, so deciding them against abilities this session is about to
                // stop having would discard them *permanently* -- the cursor moves past the whole
                // page either way, and the feed serves strictly what is after it. A page holds up
                // to 200 events (`FleetFeed::MAX_PAGE` in `robot-council/core`, and this client
                // sends no `limit`), so on a busy fleet that is a page-worth of exactly what the
                // promotion was granted for. Rewinding the cursor to this event hands the rest back
                // on the next tick, by which time the bridge has renewed.
                $id = $event['id'] ?? null;

                if (\is_int($id)) {
                    $this->cursor = $id;

                    break;
                }
            }

            // **Decided before the held set is updated, and the order is the whole of it.** A
            // `task.cancelled` for a task this session holds both concerns it AND ends the hold, so
            // tracking first would drop the task from the set and then ask whether it was in it --
            // and the answer would be no, silently, for exactly the events worth reporting.
            if ($this->concerns($event)) {
                $concerning[] = $event;
            }

            // Then the set, from every event including ones that do not concern this session:
            // a task handed away by somebody else still leaves this session's hold.
            $this->track($event);
        }

        if ($concerning === []) {
            return $roleChanged;
        }

        try {
            $this->pending->add($concerning);

            // Counted only once written: a notice for events the stop hook will not find would wake
            // an agent to an empty sink
            $this->delivered = \count($concerning);
        } catch (Throwable $throwable) {
            $diagnostic($throwable->getMessage());
        }

        return $roleChanged;
    }

    /**
     * Report an event about this session's own role, and say whether the role actually changed.
     *
     * **A denial reports and returns false**, because nothing changed and a renewal would be a
     * round trip that tells the session what it already holds. Reporting it is the point: a session
     * whose request was refused otherwise runs as `build` for the rest of its life while its
     * operator believes an approval is still pending.
     *
     * **Said once, on the event, rather than on every tick.** A demotion that announced itself
     * repeatedly, or a coordinator that collected a refusal per attempt, is the noise that stops
     * stderr being read.
     *
     * @param  array<array-key, mixed>  $event  The event, already known to name this session.
     * @param  callable(string):void  $diagnostic  Where it is reported.
     * @return bool Whether the role changed, and so whether the token needs renewing.
     */
    private function noteOwnRole(array $event, callable $diagnostic): bool
    {
        $type = $this->type($event);

        if ($type === 'session.role_changed') {
            // **Neutral about the direction, because this client cannot order the roles.** The
            // service owns that list, and a demotion is as likely as a promotion -- an imposed one
            // is core's named path for an emergency. "Renewing so it can act on it" was true of a
            // promotion and precisely backwards for the narrowing case it matters most in.
            $how = $this->metaString($event, 'how');

            $diagnostic(sprintf(
                'This session is now in the `%s` role, from `%s`%s. Refreshing what it may do.',
                $this->metaString($event, 'to') ?? 'unknown',
                $this->metaString($event, 'from') ?? 'unknown',
                $how === null ? '' : ', '.$how.' by an administrator'
            ));

            return true;
        }

        // The service records a refusal against the request rather than as a role change, so the
        // `refused` key is what tells the two apart -- a request this session made carries `to`.
        $refused = $this->metaString($event, 'refused');

        if ($type === 'session.role_requested' && $refused !== null) {
            // **"denied", because that is the word on the button the administrator pressed.** The
            // fleet administration page offers `Deny` beside a pending request, and this line told
            // the operator on the other side of that one decision that it was `refused` -- so the
            // two people looking at the same event read two different words for it, and an operator
            // hunting a refusal on the page finds a `Deny` and a request that is simply gone (#204).
            // The event's own key stays `refused`: that is the service's field name, read not
            // written, and it is what tells a denial from a request this session made.
            $diagnostic(sprintf(
                'The request for the `%s` role was denied by an administrator. This session stays `%s`.',
                $refused,
                $this->metaString($event, 'stays') ?? 'unknown'
            ));
        }

        return false;
    }

    /**
     * One string out of an event's `meta`.
     *
     * @param  array<array-key, mixed>  $event  The event.
     * @param  string  $key  The key to read.
     */
    private function metaString(array $event, string $key): ?string
    {
        $meta = $event['meta'] ?? null;

        if (! \is_array($meta)) {
            return null;
        }

        $value = $meta[$key] ?? null;

        return \is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Read the next page of the feed.
     *
     * @return array{events: list<array<array-key, mixed>>, cursor: int|null} The page.
     */
    private function read(): array
    {
        $response = $this->session->request()
            ->get($this->service.'/robot-council/api/events', ['after' => $this->cursor]);

        if (! $response->successful()) {
            // **The status travels as the exception's code**, so the caller can tell a refusal from
            // a service having a bad minute without parsing a message. Setting a property here
            // instead looks simpler and is not: the assignment is invisible to the analyzer across
            // the call boundary, which reports the caller's guard as always true.
            throw new \RuntimeException('the service answered '.$response->status().'.', $response->status());
        }

        $body = $response->json();

        if (! \is_array($body)) {
            throw new \RuntimeException('the service answered with something that is not a page.');
        }

        $events = $body['events'] ?? [];
        $cursor = $body['cursor'] ?? null;

        return [
            'events' => \is_array($events) ? array_values(array_filter($events, \is_array(...))) : [],
            'cursor' => \is_int($cursor) ? $cursor : $this->cursor,
        ];
    }

    /**
     * Update what this session is holding, from one event.
     *
     * @param  array<array-key, mixed>  $event  One event from the feed.
     */
    private function track(array $event): void
    {
        $taskId = $this->taskId($event);

        if ($taskId === null) {
            return;
        }

        $type = $this->type($event);

        if (\in_array($type, ['task.claimed', 'task.reassigned'], true)) {
            $this->assignedTo($event) === $this->session->id()
                ? $this->held[$taskId] = true
                : $this->forget($taskId);

            return;
        }

        // Anything that ends a task ends this session's hold on it, whoever did it.
        if (\in_array($type, ['task.released', 'task.cancelled', 'task.completed', 'task.failed'], true)) {
            $this->forget($taskId);
        }
    }

    /**
     * Whether an event is one this session should be told about.
     *
     * **An event this session authored is never one.** A session's own `task.claimed` reaches its
     * own feed, so without this every tool call the agent makes would queue a wake-up for the agent
     * that made it.
     *
     * @param  array<array-key, mixed>  $event  One event from the feed.
     */
    private function concerns(array $event): bool
    {
        $type = $this->type($event);

        // **This session's own presence, decided BEFORE the discard below rather than after it,
        // and that order is the whole fix** (#147).
        //
        // `robot-council/core` records a presence event against the session it is **about**, not
        // against the actor that decided it: `Support\SessionPresence` passes the session itself to
        // `FleetEvents::record()`, `Support\FleetFeed::describe()` serializes that column as
        // `actor.session_id`, and the sweep contributes no session id anywhere. So "authored by
        // this session" and "about this session" are one field, and the discard below reads it as
        // the first.
        //
        // Placed after that discard, this branch was **unreachable**: every input that satisfies it
        // had already returned false, and the test that covered it asserted the unreachable outcome
        // under a title describing the intended one. A `stale` session is recoverable, which is
        // exactly what the agent holding its claims has to hear. Its own `gone` never reaches this
        // far; see `OWN_PRESENCE`.
        if (\in_array($type, self::OWN_PRESENCE, true)
            && $this->actor($event) === $this->session->id()) {
            return true;
        }

        if ($this->actor($event) === $this->session->id()) {
            return false;
        }

        if (\in_array($type, self::ALWAYS, true)) {
            $targets = $this->targets($event);

            return $targets === null || \in_array($this->session->id(), $targets, true);
        }

        // Work handed to this session, by somebody else.
        if (\in_array($type, ['task.claimed', 'task.reassigned'], true)
            && $this->assignedTo($event) === $this->session->id()) {
            return true;
        }

        // A task this session is holding, moved by somebody else.
        if (\in_array($type, ['task.reassigned', 'task.cancelled'], true)) {
            $taskId = $this->taskId($event);

            if ($taskId !== null && isset($this->held[$taskId])) {
                return true;
            }
        }

        // A lease this session held, taken from it.
        if (\in_array($type, ['lock.taken_over', 'lock.force_released'], true)
            && $this->metaInt($event, 'taken_from') === $this->session->id()) {
            return true;
        }

        // **A coordinating session's work IS the other sessions**, so the branches above -- each
        // scoped to a task it holds, a lease it took, or its own presence -- can never fire for it.
        // Decided on `robot-council/cli#115`, which chose the ability this already grants rather
        // than minting a second one, on the reasoning that the role which needs to watch is the
        // role which needs to direct.
        //
        // **This settles WHAT arrives, and not WHEN.** `robot-council/cli#59` decided that delivery
        // reaches an agent at a turn boundary, and a turn boundary is not a wake: a session idle
        // between instructions has no turn ending, so from inside it a full sink and an empty one
        // are indistinguishable. Measured on a live fleet: one agent took nine messages addressed
        // to it across 26 hours and ran no turn from any of them. Nothing here changes that, and
        // `robot-council/cli#62` is where it is owned.
        return $this->session->allows(self::COORDINATOR)
            && \in_array($type, self::COORDINATOR_HEARS, true);
    }

    /**
     * Stop counting a task as held.
     */
    private function forget(int $taskId): void
    {
        unset($this->held[$taskId]);
    }

    /**
     * One event's type.
     *
     * @param  array<array-key, mixed>  $event  The event.
     */
    private function type(array $event): string
    {
        return \is_string($event['type'] ?? null) ? $event['type'] : '';
    }

    /**
     * The session that posted an event, when one did.
     *
     * @param  array<array-key, mixed>  $event  The event.
     */
    private function actor(array $event): ?int
    {
        $actor = $event['actor'] ?? null;

        if (! \is_array($actor)) {
            return null;
        }

        return \is_int($actor['session_id'] ?? null) ? $actor['session_id'] : null;
    }

    /**
     * The task an event names, when it names one.
     *
     * @param  array<array-key, mixed>  $event  The event.
     */
    private function taskId(array $event): ?int
    {
        return $this->metaInt($event, 'task_id');
    }

    /**
     * The session a task was handed to, when an event names one.
     *
     * @param  array<array-key, mixed>  $event  The event.
     */
    private function assignedTo(array $event): ?int
    {
        return $this->metaInt($event, 'assigned_to');
    }

    /**
     * The sessions a directive names in `meta.targets`, or null when it names nobody.
     *
     * **Anything that is not a list of session ids reads as naming nobody**, so the directive
     * reaches every session as it did before `targets` existed. Core resolves every id before it
     * records the event and stores no empty list (`Support\DirectiveTargets`), so a malformed value
     * is a service this was not written against, and the failure that costs least is an extra
     * wake-up rather than a directive nobody hears.
     *
     * @param  array<array-key, mixed>  $event  One event from the feed.
     * @return list<int>|null The named session ids, or null.
     */
    private function targets(array $event): ?array
    {
        $meta = $event['meta'] ?? null;
        $targets = \is_array($meta) ? ($meta['targets'] ?? null) : null;

        if (! \is_array($targets) || $targets === [] || ! array_is_list($targets)) {
            return null;
        }

        $ids = array_values(array_filter($targets, \is_int(...)));

        return \count($ids) === \count($targets) ? $ids : null;
    }

    /**
     * One integer out of an event's `meta`.
     *
     * @param  array<array-key, mixed>  $event  The event.
     * @param  string  $key  The key to read.
     */
    private function metaInt(array $event, string $key): ?int
    {
        $meta = $event['meta'] ?? null;

        if (! \is_array($meta)) {
            return null;
        }

        $value = $meta[$key] ?? null;

        return \is_int($value) ? $value : null;
    }
}
