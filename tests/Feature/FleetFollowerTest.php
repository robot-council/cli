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
 * Run one tick of a follower over a feed page, and hand back what it left in the sink.
 *
 * @param  list<array<string, mixed>>  $events  The page the service answers with.
 * @param  list<string>  $abilities  What the session's token carries, as the service reports it.
 * @return list<array<array-key, mixed>> What the follower decided concerns this session.
 */
function followed(array $events, array $abilities = []): array
{
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
    $sink->forget();

    new FleetFollower($session, FOLLOW_SERVICE, $sink)->tick(function (string $m): void {});

    return $sink->drain();
}

beforeEach(function (): void {
    Http::preventStrayRequests();

    // A directory this test owns. Without it the sink lands in the real `~/.local/state`, and a
    // test suite that writes into a developer's home is a test suite nobody runs twice.
    $this->stateDirectory = sys_get_temp_dir().'/rc-follower-'.bin2hex(random_bytes(6));

    putenv('XDG_STATE_HOME='.$this->stateDirectory);
});

afterEach(function (): void {
    sinkFor()->forget();

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

it('leaves this session being marked gone by the sweep', function (): void {
    expect(followed([feedEvent('session.gone', actor: MINE)]))->toBeEmpty();

    // The sweep is not this session, so a presence event about it carries somebody else's actor
    // only when the service says so. What must never happen is the session's own action waking it,
    // which the assertion above pins; a sweep-authored one is the case below.
    expect(followed([feedEvent('session.stale', actor: null)]))->toBeEmpty();
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

it('does not hand a coordinator its own events back', function (): void {
    // The own-actor exclusion is the first thing `concerns()` checks and the coordinator branch is
    // the last, so a branch that answered before it would be invisible to every other test here.
    expect(followed([
        feedEvent('task.created', actor: MINE),
        feedEvent('session.gone', actor: MINE),
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
