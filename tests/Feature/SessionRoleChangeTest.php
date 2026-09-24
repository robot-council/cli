<?php

declare(strict_types=1);

/**
 * What a bridge does when an administrator changes its session's role.
 *
 * **The service and the client disagree until something refreshes the client.** A role decided on
 * the administration page re-mints what the session's token may do straight away, but this process
 * goes on answering `Session::allows()` from the abilities it was handed at start -- and
 * `FleetFollower::concerns()` gates on exactly that. So a session promoted to `coordinator` keeps
 * discarding the events it was promoted to hear, while the service would let it post a directive.
 * The client is the one deciding what reaches the agent, so the client is the one that has to
 * notice.
 *
 * **The feed is faked as a SEQUENCE of pages, and that is what makes these tests mean anything.**
 * One `Bridge::run()` over a seekable stream makes exactly one pass through `periodic()`, because
 * `fread()` takes all 65,536 bytes at once and the pass after it meets `feof`. So a test that wrote
 * five messages and asserted "one renewal" asserted nothing -- measured: the whole file passed with
 * the backoff deleted outright. Anything about the SECOND pass therefore runs the bridge twice, on
 * the same instance, so the state the assertion is about survives between them.
 *
 * @command  vendor/bin/pest --compact tests/Feature/SessionRoleChangeTest.php
 */

use App\Support\Bridge;
use App\Support\Credentials\Credential;
use App\Support\FleetFollower;
use App\Support\PendingEvents;
use App\Support\Session;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const ROLE_SERVICE = 'https://roles.example.test';
const ROLE_INSTALLATION = 'rcouncil_1|ROLE-INSTALLATION';
const ROLE_FIRST_TOKEN = 'rcouncil_2|BEFORE';
const ROLE_SECOND_TOKEN = 'rcouncil_2|AFTER';
const ROLE_SESSION = 7;
const ROLE_OTHER_SESSION = 11;

beforeEach(function (): void {
    Http::preventStrayRequests();

    // A directory this test owns, so a sink never lands in a developer's real home.
    $this->stateDirectory = sys_get_temp_dir().'/rc-roles-'.bin2hex(random_bytes(6));

    putenv('XDG_STATE_HOME='.$this->stateDirectory);
});

afterEach(function (): void {
    roleSink()->forget();

    array_map(unlink(...), glob($this->stateDirectory.'/robot-council/pending/*') ?: []);
    @rmdir($this->stateDirectory.'/robot-council/pending');
    @rmdir($this->stateDirectory.'/robot-council');
    @rmdir($this->stateDirectory);

    putenv('XDG_STATE_HOME');
});

/**
 * The sink this test's followers write to.
 */
function roleSink(): PendingEvents
{
    return new PendingEvents(ROLE_SERVICE, 'claude', 'roles');
}

/**
 * One feed event as the service serializes it.
 *
 * The shape is `robot-council/core`'s, read from `Support\FleetFeed::describe()` on its `main`:
 * `actor.session_id` is the session the event is **about**, which for a role change is the session
 * whose role moved rather than the administrator who moved it -- the administrator is a GitHub
 * login in `performed_by`, and no session id of theirs appears anywhere. That is what makes
 * `actor($event) === $this->session->id()` the right gate.
 *
 * @param  array<string, mixed>  $meta  The event's `meta`.
 * @return array<string, mixed> The event.
 */
function roleEvent(int $id, string $type, int $actor, array $meta): array
{
    return [
        'id' => $id,
        'type' => $type,
        'body' => 'a role moved',
        // `installation_id` rides along on every event core records, so it is here too: a reader
        // that broke on an unexpected key would break against the real service.
        'meta' => ['installation_id' => 3, ...$meta],
        'created_at' => '2026-09-24T02:00:00+00:00',
        'actor' => ['session_id' => $actor, 'github_login' => 'somedev', 'coordinator_direct' => false],
        'performed_by' => ['github_login' => 'an-administrator'],
    ];
}

/**
 * A stream carrying one message, which is what gives the loop a pass to do periodic work in.
 *
 * @return resource
 */
function roleStream()
{
    $stream = tmpfile();

    fwrite($stream, '{"jsonrpc":"2.0","id":1}'."\n");
    rewind($stream);

    return $stream;
}

/**
 * Fake a service whose feed answers the given pages in order, and whose renewal hands back new
 * abilities.
 *
 * **A page per read, then an empty one forever.** A single repeated page cannot show a session
 * beginning to hear something, because the read that proves it would be the same read that was
 * discarded; and it cannot show a demotion being reported once, because the event would arrive
 * again on every tick. The real feed does neither -- the cursor moves past what was served.
 *
 * @param  list<list<array<string, mixed>>>  $pages  A page per feed read, in order.
 * @param  list<string>  $before  The abilities the session starts with.
 * @param  list<string>  $after  The abilities a renewal returns.
 * @param  int  $expiresIn  How long the first token lasts; 3600 keeps it outside the renewal window.
 * @param  int  $renewStatus  What the renewal answers.
 */
function roleService(array $pages, array $before = [], array $after = [], int $expiresIn = 3600, int $renewStatus = 200): void
{
    $feed = Http::sequence();

    foreach ($pages as $index => $events) {
        $feed->push(['events' => $events, 'cursor' => 100 + $index], 200);
    }

    // A caught-up feed, rather than the sequence throwing: a test asserting about pass two must not
    // depend on the loop making exactly the number of passes the pages were written for.
    $feed->whenEmpty(Http::response(['events' => [], 'cursor' => 999], 200));

    Http::fake([
        '*/api/sessions/'.ROLE_SESSION.'/renew' => Http::response(
            ['token' => ROLE_SECOND_TOKEN, 'expires_in' => 3600, 'abilities' => $after],
            $renewStatus
        ),
        '*/api/sessions' => Http::response([
            'session_id' => ROLE_SESSION,
            'token' => ROLE_FIRST_TOKEN,
            'expires_in' => $expiresIn,
            'feed_cursor' => 1,
            'abilities' => $before,
        ], 201),
        '*/api/events*' => $feed,
        '*/api/mcp' => Http::response('{"jsonrpc":"2.0","id":1,"result":{}}', 200),
    ]);
}

/**
 * A started session against the faked service.
 */
function roleSession(): Session
{
    $session = new Session(app(Factory::class), ROLE_SERVICE, new Credential(ROLE_INSTALLATION));

    $session->start('roles');

    return $session;
}

/**
 * A follower that reads the feed on every tick.
 *
 * `POLL_SECONDS` would let one read through and then sit out the rest of the test, which for a
 * two-pass assertion is the difference between measuring the second pass and never taking it.
 */
function roleFollower(Session $session): FleetFollower
{
    return new FleetFollower($session, ROLE_SERVICE, roleSink(), 0);
}

/**
 * The task ids waiting in the sink, in the order they were queued.
 *
 * The ids rather than a count, because a count cannot tell "delivered the event from after the
 * promotion" from "delivered the one from before it", and those are the two answers these tests
 * exist to choose between.
 *
 * @return list<mixed>
 */
function roleWaitingTaskIds(): array
{
    $ids = [];

    foreach (roleSink()->peek() as $event) {
        $meta = $event['meta'] ?? null;

        $ids[] = \is_array($meta) ? $meta['task_id'] ?? null : null;
    }

    return $ids;
}

/**
 * How many renewals were sent.
 */
function roleRenewals(): int
{
    return Http::recorded(
        fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/renew')
    )->count();
}

it('reports its own promotion and asks for a renewal', function (): void {
    roleService([[roleEvent(1, 'session.role_changed', ROLE_SESSION, ['from' => 'build', 'to' => 'coordinator', 'how' => 'approved'])]]);

    $said = [];
    $follower = roleFollower(roleSession());

    $changed = $follower->tick(function (string $message) use (&$said): void {
        $said[] = $message;
    });

    expect($changed)->toBeTrue()
        ->and($said)->toHaveCount(1)
        ->and($said[0])->toContain('coordinator')
        ->and($said[0])->toContain('build');
});

it('ignores a role change that names another session', function (): void {
    // The negative control. Without it the assertion above would pass just as happily against a
    // follower that renewed on every role event on the fleet, which on a busy fleet is a renewal
    // per administrator click. The fleet serves these to everybody: only narration is restricted,
    // read from `FleetFeed::visibleWithin()` on core's `main`.
    roleService([[roleEvent(1, 'session.role_changed', ROLE_OTHER_SESSION, ['from' => 'build', 'to' => 'coordinator'])]]);

    $said = [];
    $follower = roleFollower(roleSession());

    $changed = $follower->tick(function (string $message) use (&$said): void {
        $said[] = $message;
    });

    expect($changed)->toBeFalse()
        ->and($said)->toBeEmpty();
});

it('reports a refusal, and does not renew for it', function (): void {
    // Core records a refusal against the REQUEST rather than as a role change, with
    // `['refused' => …, 'stays' => …]` where a request carries `['from' => …, 'to' => …]` -- read
    // from `Support\RoleRequests::deny()` on its `main`. The `refused` key is what tells a denial
    // from this session's own request, which needs no report at all.
    roleService([[roleEvent(1, 'session.role_requested', ROLE_SESSION, ['refused' => 'coordinator', 'stays' => 'build'])]]);

    $said = [];
    $follower = roleFollower(roleSession());

    $changed = $follower->tick(function (string $message) use (&$said): void {
        $said[] = $message;
    });

    expect($changed)->toBeFalse()
        ->and($said)->toHaveCount(1)
        ->and($said[0])->toContain('refused')
        ->and($said[0])->toContain('coordinator');
});

it('says nothing about a request this session made itself', function (): void {
    // The other half of the refusal control: `session.role_requested` names this session both when
    // it asked and when it was turned down, and only the second is news.
    roleService([[roleEvent(1, 'session.role_requested', ROLE_SESSION, ['from' => 'build', 'to' => 'coordinator'])]]);

    $said = [];
    $follower = roleFollower(roleSession());

    $changed = $follower->tick(function (string $message) use (&$said): void {
        $said[] = $message;
    });

    expect($changed)->toBeFalse()
        ->and($said)->toBeEmpty();
});

it('renews at once on a promotion, without waiting for the renewal window', function (): void {
    roleService(
        pages: [[roleEvent(1, 'session.role_changed', ROLE_SESSION, ['from' => 'build', 'to' => 'coordinator'])]],
        before: ['tasks:create'],
        after: ['tasks:create', 'coordinator:direct'],
    );

    $session = roleSession();

    // The token has an hour left, so nothing about the schedule is due. Only the role change can
    // bring the renewal forward.
    expect($session->allows('coordinator:direct'))->toBeFalse();

    new Bridge($session, ROLE_SERVICE, Bridge::HEARTBEAT_SECONDS, roleFollower($session))
        ->run(roleStream(), tmpfile(), fn (string $m): null => null);

    expect(roleRenewals())->toBe(1)
        // The point of the renewal: what this session believes it may do now matches what the
        // service will let it do, so `FleetFollower::concerns()` stops discarding what it was
        // promoted to hear.
        ->and($session->allows('coordinator:direct'))->toBeTrue();
});

it('does not renew when nothing changed and nothing is due', function (): void {
    // The other half of the control: a bridge that renewed on every pass would satisfy the test
    // above while hammering the service.
    roleService(
        pages: [[roleEvent(1, 'task.created', ROLE_OTHER_SESSION, ['task_id' => 5])]],
        before: ['tasks:create'],
    );

    $session = roleSession();

    new Bridge($session, ROLE_SERVICE, Bridge::HEARTBEAT_SECONDS, roleFollower($session))
        ->run(roleStream(), tmpfile(), fn (string $m): null => null);

    expect(roleRenewals())->toBe(0);
});

it('delivers the fleet-wide events it was discarding before the promotion', function (): void {
    // The promotion's whole point, and the only assertion here that is about the AGENT rather than
    // about the token: `concerns()` gates `task.created` on `allows('coordinator:direct')`, so
    // until the renewal lands the session drops the very events it was promoted to watch.
    roleService(
        pages: [
            [
                roleEvent(1, 'session.role_changed', ROLE_SESSION, ['from' => 'build', 'to' => 'coordinator']),
                // Same page as the promotion, so it is decided on the old abilities and dropped.
                // That is honest rather than a gap: the renewal happens after the tick returns.
                roleEvent(2, 'task.created', ROLE_OTHER_SESSION, ['task_id' => 41]),
            ],
            [roleEvent(3, 'task.created', ROLE_OTHER_SESSION, ['task_id' => 42])],
        ],
        before: ['tasks:create'],
        after: ['tasks:create', 'coordinator:direct'],
    );

    $session = roleSession();
    $bridge = new Bridge($session, ROLE_SERVICE, Bridge::HEARTBEAT_SECONDS, roleFollower($session));

    $bridge->run(roleStream(), tmpfile(), fn (string $m): null => null);

    // Nothing yet: the promotion was seen on this pass, so task 41 met the old abilities.
    expect(roleWaitingTaskIds())->toBeEmpty();

    $bridge->run(roleStream(), tmpfile(), fn (string $m): null => null);

    // Task 42 and only task 42. Task 41 is not there, which is what says the change took effect
    // between the two reads rather than the sink having been open all along.
    expect(roleWaitingTaskIds())->toBe([42]);
});

it('reports a demotion once, and stops delivering fleet-wide events', function (): void {
    roleService(
        pages: [
            [roleEvent(1, 'session.role_changed', ROLE_SESSION, ['from' => 'coordinator', 'to' => 'build', 'how' => 'imposed'])],
            [roleEvent(2, 'task.created', ROLE_OTHER_SESSION, ['task_id' => 41])],
            [roleEvent(3, 'task.created', ROLE_OTHER_SESSION, ['task_id' => 42])],
        ],
        before: ['tasks:create', 'coordinator:direct'],
        after: ['tasks:create'],
    );

    $session = roleSession();
    $said = [];
    $record = function (string $message) use (&$said): void {
        $said[] = $message;
    };

    $bridge = new Bridge($session, ROLE_SERVICE, Bridge::HEARTBEAT_SECONDS, roleFollower($session));

    $bridge->run(roleStream(), tmpfile(), $record);
    $bridge->run(roleStream(), tmpfile(), $record);
    $bridge->run(roleStream(), tmpfile(), $record);

    // Said on the event and not on every tick: the cursor moves past it, so three passes produce
    // one line. A demotion that announced itself on every pass is the noise that stops stderr being
    // read at all.
    expect($said)->toHaveCount(1)
        ->and($said[0])->toContain('build')
        ->and($session->allows('coordinator:direct'))->toBeFalse()
        // And it stops acting as one: both later `task.created` events are discarded, where before
        // the demotion they would have been queued for the agent.
        ->and(roleWaitingTaskIds())->toBeEmpty();
});

it('backs off a renewal that fails rather than retrying it tightly', function (): void {
    // Two role changes, one per pass, so the renewal is due on both. Without the backoff the second
    // pass renews again -- and on a real bridge "every pass" is ten attempts a minute while idle,
    // and the message rate when busy.
    roleService(
        pages: [
            [roleEvent(1, 'session.role_changed', ROLE_SESSION, ['from' => 'build', 'to' => 'coordinator'])],
            [roleEvent(2, 'session.role_changed', ROLE_SESSION, ['from' => 'coordinator', 'to' => 'build'])],
        ],
        before: ['tasks:create'],
        renewStatus: 500,
    );

    $session = roleSession();
    $said = [];
    $record = function (string $message) use (&$said): void {
        $said[] = $message;
    };

    // The same bridge both times, because `nextRenewAttempt` is its state and a second instance
    // would start with a clean one -- which is exactly the bug this asserts against.
    $bridge = new Bridge($session, ROLE_SERVICE, Bridge::HEARTBEAT_SECONDS, roleFollower($session));

    $bridge->run(roleStream(), tmpfile(), $record);
    $bridge->run(roleStream(), tmpfile(), $record);

    expect(roleRenewals())->toBe(1)
        ->and($session->allows('coordinator:direct'))->toBeFalse()
        // And it says so, rather than failing silently.
        ->and(implode(' ', $said))->toContain('could not be renewed');
});

it('writes nothing to stdout while doing any of this', function (): void {
    roleService(
        pages: [[roleEvent(1, 'session.role_changed', ROLE_SESSION, ['from' => 'build', 'to' => 'coordinator'])]],
        before: ['tasks:create'],
        after: ['tasks:create', 'coordinator:direct'],
    );

    $session = roleSession();
    $out = tmpfile();

    new Bridge($session, ROLE_SERVICE, Bridge::HEARTBEAT_SECONDS, roleFollower($session))
        ->run(roleStream(), $out, fn (string $m): null => null);

    rewind($out);
    $written = (string) stream_get_contents($out);

    // Exactly the one protocol reply the forwarded message earned, and nothing the role change
    // said: a harness parses this stream, so a diagnostic here is a malformed message.
    expect($written)->toBe('{"jsonrpc":"2.0","id":1,"result":{}}'."\n");
});
