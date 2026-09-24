<?php

declare(strict_types=1);

/**
 * What the follower reads off the fleet's feed, and what it decides concerns this session.
 *
 * **Every path into the fleet is agent-initiated, so an agent that is not running a turn learns
 * nothing.** A directive, a hand-back, or a lock being force-released sits in the feed until
 * something causes that agent to read it, and the interval is unbounded rather than slow, because
 * nothing schedules the next read (cli#59, cli#60).
 *
 * The filter is the part worth testing hardest. **Waking on everything would re-create the problem
 * the fleet is leaving**: each agent would have to decide for itself whether an event was its own,
 * which is precisely the judgement the feed's visibility rule exists to avoid asking of a process
 * that may have shell access.
 *
 * @command  vendor/bin/pest --compact tests/Feature/FleetFollowerTest.php
 */
use App\Support\Credentials\Credential;
use App\Support\FleetFollower;
use App\Support\PendingEvents;
use App\Support\Session;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;

const FOLLOW_SERVICE = 'https://fleet.example.test';
const FOLLOW_INSTALLATION = 'rcouncil_1|FOLLOW-INSTALLATION-0123456789';
const MINE = 7;
const THEIRS = 9;

/**
 * A sink under a directory this test owns, so nothing touches a real home.
 */
function sinkFor(string $project = 'probe'): PendingEvents
{
    return new PendingEvents(FOLLOW_SERVICE, 'claude', $project);
}

/**
 * One event as the feed serializes it.
 *
 * @param  array<string, mixed>  $meta  The event's `meta`.
 * @return array<string, mixed> The event.
 */
function feedEvent(string $type, ?int $actor = THEIRS, array $meta = [], string $body = 'something'): array
{
    return [
        'id' => random_int(1000, 9999),
        'type' => $type,
        'body' => $body,
        'meta' => $meta,
        'created_at' => '2026-09-22T12:00:00+00:00',
        'actor' => ['session_id' => $actor, 'github_login' => 'otherdev', 'coordinator_direct' => false],
    ];
}

/**
 * How many times `followed()` has been called in the current test.
 *
 * **`Http::fake()` MERGES its stubs into the ones already registered**, so a second `followed()` in
 * one test leaves the first call's events stub matching first -- and returns the FIRST
 * call's events under the second call's name. Measured twice: here while building #147, where it
 * returned a plausible wrong answer, and in `EnrollCommandTest` while building #146, where the same
 * merge left an exhausted `Http::sequence` matching and the run exited 1. The loud failure is the
 * lucky one; this makes the quiet one loud as well.
 *
 * A counter reset in `beforeEach` rather than a key taken from the test's name: `test()->name()` is
 * `@internal` to PHPUnit and untyped through Pest's proxy, so PHPStan refuses it at `max`.
 *
 * @param  bool  $reset  Whether to zero the count rather than increment it.
 * @return int The number of calls so far in this test, after this one.
 */
function followedCalls(bool $reset = false): int
{
    /** @var int $count */
    static $count = 0;

    if ($reset) {
        return $count = 0;
    }

    return ++$count;
}

/**
 * Run one tick of a follower over a feed page, and hand back what it left in the sink.
 *
 * @param  list<array<string, mixed>>  $events  The page the service answers with.
 * @param  list<string>  $abilities  What the session's token carries, as the service reports it.
 * @return list<array<array-key, mixed>> What the follower decided concerns this session.
 */
function followed(array $events, array $abilities = []): array
{
    if (followedCalls() > 1) {
        throw new RuntimeException(
            'followed() was called twice in one test. Http::fake() merges stubs, so this call would '.
            "be served the first call's events. Pass every event to a single call instead."
        );
    }

    Http::fake([
        // **`abilities` is what the deployment actually returns**, and this fake omitted it until
        // cli#116. Harmless while nothing read the field; not harmless once a gate depends on it,
        // which is the drift a fake is most able to hide.
        '*/api/sessions' => Http::response([
            'session_id' => MINE,
            'token' => 'rcouncil_2|T',
            'expires_in' => 3600,
            'feed_cursor' => 1,
            'abilities' => $abilities,
        ], 201),
        '*/api/events*' => Http::response(['events' => $events, 'cursor' => 99], 200),
    ]);

    $session = new Session(app(Factory::class), FOLLOW_SERVICE, new Credential(FOLLOW_INSTALLATION));
    $session->start();

    $sink = sinkFor();

    // Removed rather than cleared: `clearFleetEvents()` deliberately KEEPS a `bridge.` entry, so
    // it cannot promise this helper the empty sink its callers count each result against.
    if (is_file($sink->path())) {
        unlink($sink->path());
    }

    new FleetFollower($session, FOLLOW_SERVICE, $sink)->tick(function (string $m): void {});

    return $sink->drain();
}

beforeEach(function (): void {
    Http::preventStrayRequests();

    followedCalls(reset: true);

    // A directory this test owns. Without it the sink lands in the real `~/.local/state`, and a
    // test suite that writes into a developer's home is a test suite nobody runs twice.
    $this->stateDirectory = sys_get_temp_dir().'/rc-follower-'.bin2hex(random_bytes(6));

    putenv('XDG_STATE_HOME='.$this->stateDirectory);
});

afterEach(function (): void {
    if (is_dir($this->stateDirectory)) {
        // Removed rather than left: a suite that leaves a directory per test in the system
        // temporary directory is a suite that fills a disk over a month of runs.
        array_map(unlink(...), glob($this->stateDirectory.'/robot-council/pending/*') ?: []);
        @rmdir($this->stateDirectory.'/robot-council/pending');
        @rmdir($this->stateDirectory.'/robot-council');
        @rmdir($this->stateDirectory);
    }

    putenv('XDG_STATE_HOME');
});

it('leaves a directive, whoever it came from', function (): void {
    expect(followed([feedEvent('directive', body: 'everyone stop')]))->toHaveCount(1);
});

it('ignores an event this session authored', function (): void {
    // **The one that matters most.** A session's own `task.claimed` reaches its own feed, so
    // without this every tool call the agent makes queues a wake-up for the agent that made it.
    expect(followed([feedEvent('directive', actor: MINE)]))->toBeEmpty();
});

it('leaves work handed to this session', function (): void {
    expect(followed([feedEvent('task.reassigned', meta: ['task_id' => 3, 'assigned_to' => MINE])]))
        ->toHaveCount(1);
});

it('ignores work handed to somebody else', function (): void {
    expect(followed([feedEvent('task.reassigned', meta: ['task_id' => 3, 'assigned_to' => THEIRS])]))
        ->toBeEmpty();
});

it('leaves a task this session was holding being taken away', function (): void {
    // Two events in one page: the claim that makes it this session's, then somebody else moving it.
    // The claim is authored by this session, so it must NOT itself be left in the sink -- only the
    // reassignment should be, which is what tells the two behaviours apart.
    $left = followed([
        feedEvent('task.claimed', actor: MINE, meta: ['task_id' => 3, 'assigned_to' => MINE]),
        feedEvent('task.cancelled', meta: ['task_id' => 3]),
    ]);

    expect($left)->toHaveCount(1)
        ->and($left[0]['type'])->toBe('task.cancelled');
});

it('ignores a task it never held being cancelled', function (): void {
    expect(followed([feedEvent('task.cancelled', meta: ['task_id' => 3])]))->toBeEmpty();
});

it('stops caring about a task once it has let it go', function (): void {
    $left = followed([
        feedEvent('task.claimed', actor: MINE, meta: ['task_id' => 3, 'assigned_to' => MINE]),
        feedEvent('task.completed', actor: MINE, meta: ['task_id' => 3]),
        feedEvent('task.cancelled', meta: ['task_id' => 3]),
    ]);

    expect($left)->toBeEmpty();
});

it('leaves a lease taken from this session', function (): void {
    expect(followed([feedEvent('lock.force_released', meta: ['lock' => 'deploy', 'taken_from' => MINE])]))
        ->toHaveCount(1);
});

it('ignores a lease taken from somebody else', function (): void {
    expect(followed([feedEvent('lock.taken_over', meta: ['lock' => 'deploy', 'taken_from' => THEIRS])]))
        ->toBeEmpty();
});

it('hands this session its own stale, which the sweep decided rather than it', function (): void {
    // **The shape `robot-council/core` actually serializes**, which is what the version of this test
    // before #147 got wrong. `Support\SessionPresence` records a presence event against the session
    // it is ABOUT, so `Support\FleetFeed::describe()` puts that session's id in `actor.session_id`
    // -- the sweep contributes no id anywhere. The old fixture used `actor: null`, a shape no
    // service produces, so it exercised the fixture rather than the service, and its sibling
    // asserted the event was discarded under a title saying it was delivered.
    //
    // A `stale` session is recoverable, and the agent holding its claims has to hear it.
    //
    // **No `gone` in this fixture, and not by omission (#175).** A feed page carrying this
    // session's own `session.gone` is a response the service cannot send: the event is written in
    // the transaction that deletes the session's tokens, behind middleware that refuses a gone
    // session. The earlier version of this test asserted delivery against exactly that page.
    // `SessionGoneTest` covers how a gone session is actually learned, from the renewal's `409`.
    expect(array_column(followed([
        feedEvent('session.stale', actor: MINE),
    ]), 'type'))->toBe(['session.stale']);
});

it('still discards everything this session genuinely authored', function (): void {
    // The other side of the branch added by #147, and the reason it is keyed on the TYPE rather
    // than on the actor alone: the discard it now runs ahead of is what stops an agent being told
    // what it just did, and only the two presence types are decided by somebody else.
    expect(followed([
        feedEvent('task.claimed', actor: MINE),
        feedEvent('task.completed', actor: MINE),
        feedEvent('narration', actor: MINE),
        feedEvent('session.resumed', actor: MINE),
    ]))->toBeEmpty();
});

it('still discards another session being marked stale or gone, for a session with no ability', function (): void {
    // The new branch is scoped to THIS session. Another session's presence reaches a coordinator
    // and nobody else, which is what #116 built and what the test below pins from the other side.
    expect(followed([
        feedEvent('session.stale', actor: THEIRS),
        feedEvent('session.gone', actor: THEIRS),
    ]))->toBeEmpty();
});

it('ignores narration, which is not something to act on', function (): void {
    expect(followed([feedEvent('narration', body: 'reading the migration')]))->toBeEmpty();
});

it('keeps reading after a failure, and backs off rather than retrying every pass', function (): void {
    Http::fake([
        '*/api/sessions' => Http::response(['session_id' => MINE, 'token' => 'rcouncil_2|T', 'expires_in' => 3600, 'feed_cursor' => 1], 201),
        '*/api/events*' => Http::response('nope', 500),
    ]);

    $session = new Session(app(Factory::class), FOLLOW_SERVICE, new Credential(FOLLOW_INSTALLATION));
    $session->start();

    $said = [];

    $follower = new FleetFollower($session, FOLLOW_SERVICE, sinkFor());

    $follower->tick(function (string $m) use (&$said): void {
        $said[] = $m;
    });

    // A second tick immediately afterwards must not read again: without the backoff this retried on
    // every pass, measured at ten attempts a minute while idle on the renewal path it copies.
    $follower->tick(function (string $m) use (&$said): void {
        $said[] = $m;
    });

    expect($said)->toHaveCount(1)
        ->and($said[0])->toContain('Could not read the fleet feed');

    Http::assertSentCount(2);
});

it("leaves the fleet's own activity alone for an ordinary session", function (): void {
    // The control for every coordinator test below, and the guarantee that this change is inert
    // for the sessions that did not ask for it. Identical input, one ability apart.
    expect(followed([
        feedEvent('session.gone'),
        feedEvent('task.created'),
        feedEvent('lock.acquired'),
    ]))->toBeEmpty();
});

it("hands the fleet's activity to a session holding coordinator:direct", function (): void {
    // A coordinating session holds no task and no lease, so every other branch of `concerns()` is
    // unreachable for it. Decided on #115.
    $kept = followed([
        feedEvent('session.gone'),
        feedEvent('task.created'),
        feedEvent('lock.acquired'),
    ], ['coordinator:direct']);

    expect($kept)->toHaveCount(3)
        ->and(array_column($kept, 'type'))->toBe(['session.gone', 'task.created', 'lock.acquired']);
});

it('does not hand a coordinator its own events back, except its own presence', function (): void {
    // The own-actor exclusion is the second thing `concerns()` checks and the coordinator branch is
    // the last, so a branch that answered between them would be invisible to every other test here.
    //
    // **Its own presence moved out of this assertion in #147 and that is the change, not a
    // relaxation.** A coordinator was being told about every other session going stale while never
    // being told about itself, because a presence event names the session it is about and the
    // discard read that as authorship. What a coordinator still does not get back is what it did.
    //
    // `session.stale` rather than `session.gone`, which this test used until #175: a session's own
    // `gone` is a feed page the service cannot serve it, so asserting on one tested a fixture.
    expect(array_column(followed([
        feedEvent('task.created', actor: MINE),
        feedEvent('narration', actor: MINE),
        feedEvent('session.stale', actor: MINE),
    ], ['coordinator:direct']), 'type'))->toBe(['session.stale']);
});

it("hands a coordinator another session's role change and refusal", function (): void {
    // #115 decided a coordinating session hears about the other sessions, and #116 built the list
    // without these two. A role is more squarely "what the other sessions are" than a lock being
    // acquired, which was on the list from the start.
    //
    // Both are asserted in one call, because `followed()` refuses a second in one test -- see the
    // note on `followedCalls()`.
    expect(array_column(followed([
        feedEvent('session.role_changed', actor: THEIRS, meta: ['from' => 'build', 'to' => 'coordinator', 'how' => 'approved']),
        feedEvent('session.role_requested', actor: THEIRS, meta: ['refused' => 'coordinator', 'stays' => 'build']),
    ], ['coordinator:direct']), 'type'))->toBe(['session.role_changed', 'session.role_requested']);
});

it('hands a session with no ability neither of those', function (): void {
    // The other side, against the SAME events. Without this the test above would pass just as
    // happily against a follower that handed every role event to everybody, which would put one
    // developer's promotions into another developer's agent.
    expect(followed([
        feedEvent('session.role_changed', actor: THEIRS, meta: ['from' => 'build', 'to' => 'coordinator']),
        feedEvent('session.role_requested', actor: THEIRS, meta: ['refused' => 'coordinator']),
    ]))->toBeEmpty();
});

it("does not queue a coordinator's own role change into the sink", function (): void {
    // #129's behavior, which this must not change: a session's OWN role change is reported to the
    // operator on stderr and renewed for, and the loop `break`s the page at it rather than letting
    // it reach `concerns()`. Adding the type to `COORDINATOR_HEARS` could have routed it to the
    // sink as well, which would tell an agent what an administrator just did to it twice, in two
    // different voices.
    expect(followed([
        feedEvent('session.role_changed', actor: MINE, meta: ['from' => 'build', 'to' => 'coordinator']),
    ], ['coordinator:direct']))->toBeEmpty();
});

it("does not hand a coordinator another developer's narration", function (): void {
    // **Narration is the one type the service restricts**, and widening it here would put another
    // developer's words into an agent with shell access on a client-side check alone.
    expect(followed([
        feedEvent('narration', body: 'thinking out loud'),
    ], ['coordinator:direct']))->toBeEmpty();
});

it('still hands a coordinator a directive, which needed no ability to receive', function (): void {
    // Receiving a directive never depended on holding anything, and must not start.
    expect(followed([feedEvent('directive')], ['coordinator:direct']))->toHaveCount(1);
});

it('reads the ability from the service rather than assuming it', function (): void {
    // An ability the service did not name is not held. An older deployment that omits the field
    // therefore narrows what reaches an agent rather than widening it.
    expect(followed([feedEvent('task.created')], ['tasks:create', 'tasks:claim']))->toBeEmpty();
});

it('matches an ability exactly, so a loose comparison cannot grant one nobody named', function (): void {
    // **`in_array`'s strict flag is load-bearing.** PHP compares two numeric strings by value when
    // it is dropped, so `'0'` would match `'0.0'` and a session would hold an ability the service
    // never named. Abilities gate what reaches an agent, so the match has to be the string.
    Http::fake([
        '*/api/sessions' => Http::response([
            'session_id' => MINE, 'token' => 'rcouncil_2|T', 'expires_in' => 3600,
            'feed_cursor' => 1, 'abilities' => ['0'],
        ], 201),
    ]);

    $session = new Session(app(Factory::class), FOLLOW_SERVICE, new Credential(FOLLOW_INSTALLATION));
    $session->start();

    expect($session->allows('0'))->toBeTrue()
        // Loosely equal to `'0'`, and not an ability anybody granted.
        ->and($session->allows('0.0'))->toBeFalse();
});

it('holds nothing when the service names no abilities, rather than holding everything', function (): void {
    // An older deployment that omits the field narrows what reaches an agent rather than widening
    // it, which is the safe direction for a value that decides what interrupts somebody.
    Http::fake([
        '*/api/sessions' => Http::response([
            'session_id' => MINE, 'token' => 'rcouncil_2|T', 'expires_in' => 3600, 'feed_cursor' => 1,
        ], 201),
    ]);

    $session = new Session(app(Factory::class), FOLLOW_SERVICE, new Credential(FOLLOW_INSTALLATION));
    $session->start();

    expect($session->allows('coordinator:direct'))->toBeFalse();
});
