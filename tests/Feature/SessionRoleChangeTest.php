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
 * **A page here is what the service would answer for the cursor the follower actually sent**, not a
 * fixed reply. `Http::sequence()` ignores the query string, so a test that cares which cursor was
 * sent asserts it against `Http::recorded()` rather than trusting the fake to have honored it.
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
function roleEvent(int $id, string $type, int $actor, array $meta = []): array
{
    return [
        'id' => $id,
        'type' => $type,
        'body' => 'something happened',
        // `installation_id` rides along on the events `Support\RoleRequests` and
        // `Support\SessionPresence` record, though not on task or lock events. It is here so a
        // reader that broke on an unexpected key would break against the real service rather than
        // only in production.
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
 * @param  int  $expiresIn  How long the first token lasts. 3600 keeps it outside the renewal
 *                          window; a value at or under `Bridge::RENEW_WITHIN_SECONDS` puts it
 *                          inside, which is how the scheduled half of the condition is exercised.
 * @param  int|list<int>  $renewStatus  What the renewal answers, or a status per attempt.
 */
function roleService(array $pages, array $before = [], array $after = [], int $expiresIn = 3600, int|array $renewStatus = 200): void
{
    $feed = Http::sequence();

    foreach ($pages as $index => $events) {
        $feed->push(['events' => $events, 'cursor' => 100 + $index], 200);
    }

    // A caught-up feed, rather than the sequence throwing: a test asserting about a later pass must
    // not depend on the loop making exactly the number of passes the pages were written for.
    $feed->whenEmpty(Http::response(['events' => [], 'cursor' => 999], 200));

    $renewed = ['token' => ROLE_SECOND_TOKEN, 'expires_in' => 3600, 'abilities' => $after];

    if (\is_array($renewStatus)) {
        $renewal = Http::sequence();

        foreach ($renewStatus as $status) {
            $renewal->push($renewed, $status);
        }

        $renewal->whenEmpty(Http::response($renewed, 200));
    } else {
        $renewal = Http::response($renewed, $renewStatus);
    }

    Http::fake([
        '*/api/sessions/'.ROLE_SESSION.'/renew' => $renewal,
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
function roleFollower(Session $session, int $pollSeconds = 0): FleetFollower
{
    return new FleetFollower($session, ROLE_SERVICE, roleSink(), $pollSeconds);
}

/**
 * A bridge over the faked service, with the retry interval a test can collapse.
 */
function roleBridge(Session $session, int $renewRetrySeconds = Bridge::RENEW_RETRY_SECONDS): Bridge
{
    return new Bridge(
        $session,
        ROLE_SERVICE,
        Bridge::HEARTBEAT_SECONDS,
        roleFollower($session),
        $renewRetrySeconds
    );
}

/**
 * The ids waiting in the sink, in the order they were queued.
 *
 * Ids rather than a count, because a count cannot tell "delivered the event from after the
 * promotion" from "delivered the one from before it", and those are the two answers these tests
 * exist to choose between.
 *
 * @return list<mixed>
 */
function roleWaitingIds(): array
{
    $ids = [];

    foreach (roleSink()->peek() as $event) {
        $ids[] = $event['id'] ?? null;
    }

    return $ids;
}

/**
 * The `after` cursor sent on each feed read, in order.
 *
 * `Http::sequence()` ignores the query string, so the fake answers page two whatever the follower
 * asked for. Without this the tests would be asserting the fixture's order rather than the
 * follower's cursor, and deleting the line that advances the cursor would survive them.
 *
 * @return list<string>
 */
function roleCursorsSent(): array
{
    $sent = [];

    foreach (Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/api/events')) as $exchange) {
        parse_str((string) parse_url((string) $exchange[0]->url(), \PHP_URL_QUERY), $query);

        $sent[] = \is_string($query['after'] ?? null) ? $query['after'] : '';
    }

    return $sent;
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

    // Positioned, not `toContain('coordinator')`. Both roles appear in every one of these messages,
    // so an unpositioned match is satisfied by the two `sprintf` arguments swapped -- which is the
    // one way this sentence can be wrong and the one a reader would act on.
    expect($changed)->toBeTrue()
        ->and($said)->toHaveCount(1)
        ->and($said[0])->toContain('now in the `coordinator` role')
        ->and($said[0])->toContain('from `build`')
        ->and($said[0])->toContain('approved by an administrator');
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

    // The message is this branch's ONLY effect -- it returns false and nothing else happens -- so
    // which role went in which slot is the whole of what there is to get right. Reversed, it tells
    // an operator their session was refused the role it actually kept.
    expect($changed)->toBeFalse()
        ->and($said)->toHaveCount(1)
        ->and($said[0])->toContain('for the `coordinator` role was refused')
        ->and($said[0])->toContain('stays `build`');
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

it('answers false, and reads nothing, when the feed is not due yet', function (): void {
    // The early return `tick()` gained along with its return type. Answering true here would renew
    // on roughly every other pass forever -- `POLL_SECONDS` is 10 against a 5-second tick -- which
    // is the renewal storm the backoff exists to prevent, arriving through the door this change
    // opened.
    roleService([[roleEvent(1, 'session.role_changed', ROLE_SESSION, ['from' => 'build', 'to' => 'coordinator'])]]);

    $follower = roleFollower(roleSession(), FleetFollower::POLL_SECONDS);
    $quiet = fn (string $message): null => null;

    expect($follower->tick($quiet))->toBeTrue()
        ->and($follower->tick($quiet))->toBeFalse()
        // And it did not spend a request finding that out.
        ->and(roleCursorsSent())->toHaveCount(1);
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

    roleBridge($session)->run(roleStream(), tmpfile(), fn (string $m): null => null);

    expect(roleRenewals())->toBe(1)
        // The point of the renewal: what this session believes it may do now matches what the
        // service will let it do, so `FleetFollower::concerns()` stops discarding what it was
        // promoted to hear.
        ->and($session->allows('coordinator:direct'))->toBeTrue();
});

it('still renews on the schedule when no role changed', function (): void {
    // The other half of the rewritten condition, which had no coverage anywhere in the repository:
    // every test in `tests/` started a session with 3600 seconds, so nothing ever sat inside
    // `RENEW_WITHIN_SECONDS` and dropping `|| expiringWithin(…)` passed the whole suite. That is
    // the path that keeps a bridge alive past its first hour.
    roleService(
        pages: [[]],
        before: ['tasks:create'],
        after: ['tasks:create'],
        expiresIn: Bridge::RENEW_WITHIN_SECONDS - 1,
    );

    $session = roleSession();

    roleBridge($session)->run(roleStream(), tmpfile(), fn (string $m): null => null);

    expect(roleRenewals())->toBe(1);
});

it('does not renew when nothing changed and nothing is due', function (): void {
    // The other half of the control: a bridge that renewed on every pass would satisfy the tests
    // above while hammering the service.
    roleService(
        pages: [[roleEvent(1, 'task.created', ROLE_OTHER_SESSION, ['task_id' => 5])]],
        before: ['tasks:create'],
    );

    $session = roleSession();

    roleBridge($session)->run(roleStream(), tmpfile(), fn (string $m): null => null);

    expect(roleRenewals())->toBe(0);
});

it('carries a role change across a failed renewal rather than dropping it', function (): void {
    // **The asymmetry that makes this necessary.** `expiringWithin()` re-derives itself from the
    // token every pass, so a renewal the backoff refuses is merely postponed. A role change is one
    // event, already behind the cursor before the renewal is even attempted, so without a latch the
    // same refusal DROPS it -- one 502 and the session runs on the abilities it held before the
    // administrator decided, for up to the renewal window. That is the defect this feature exists
    // to close, reinstated by a single transient failure.
    roleService(
        pages: [[roleEvent(1, 'session.role_changed', ROLE_SESSION, ['from' => 'build', 'to' => 'coordinator'])], []],
        before: ['tasks:create'],
        after: ['tasks:create', 'coordinator:direct'],
        renewStatus: [500, 200],
    );

    $session = roleSession();
    $said = [];
    $record = function (string $message) use (&$said): void {
        $said[] = $message;
    };

    // Zero retry, so the second pass is allowed to try again within the test's own second. The
    // backoff being collapsed is the point: what is under test is whether the INTENT survived it,
    // not how long the wait is.
    $bridge = roleBridge($session, renewRetrySeconds: 0);

    $bridge->run(roleStream(), tmpfile(), $record);

    // The first attempt failed and the second page carries no role event at all, so nothing but the
    // latch can produce another attempt.
    expect(roleRenewals())->toBe(1)
        ->and($session->allows('coordinator:direct'))->toBeFalse();

    $bridge->run(roleStream(), tmpfile(), $record);

    expect(roleRenewals())->toBe(2)
        ->and($session->allows('coordinator:direct'))->toBeTrue()
        ->and(implode(' ', $said))->toContain('could not be renewed');
});

it('renews once for a role change that keeps arriving, not once per pass', function (): void {
    // A successful renewal used to leave `nextRenewAttempt` untouched, so only the poll interval
    // bounded this. `FleetFollower::read()` keeps the previous cursor when a page's own is not an
    // int, so a service answering one that is not re-serves the same page forever -- six renewals a
    // minute against the sixty a minute `core` allows an installation, shared by every bridge on
    // the machine.
    roleService(
        pages: [
            [roleEvent(1, 'session.role_changed', ROLE_SESSION, ['from' => 'build', 'to' => 'coordinator'])],
            [roleEvent(1, 'session.role_changed', ROLE_SESSION, ['from' => 'build', 'to' => 'coordinator'])],
        ],
        before: ['tasks:create'],
        after: ['tasks:create', 'coordinator:direct'],
    );

    $session = roleSession();
    $bridge = roleBridge($session);

    $bridge->run(roleStream(), tmpfile(), fn (string $m): null => null);
    $bridge->run(roleStream(), tmpfile(), fn (string $m): null => null);

    expect(roleRenewals())->toBe(1);
});

it('defers the rest of the page rather than deciding it on the old abilities', function (): void {
    // **A page holds up to 200 events**, not the one this was first written with. `FleetFeed::MAX_PAGE`
    // is 200 in `robot-council/core` and this client sends no `limit`, so everything below the
    // promotion in its page was being decided against the abilities the session was about to stop
    // having -- and then the cursor moved past the whole page, so they were gone rather than late.
    roleService(
        pages: [
            [
                // Before the promotion, and concerning either way: `directive` is in `ALWAYS`, so
                // it needs no ability. It also puts a non-empty `$concerning` alongside a role
                // change, which is the `tick()` exit that nothing else here reaches.
                roleEvent(1, 'directive', ROLE_OTHER_SESSION),
                roleEvent(2, 'session.role_changed', ROLE_SESSION, ['from' => 'build', 'to' => 'coordinator']),
                roleEvent(3, 'task.created', ROLE_OTHER_SESSION, ['task_id' => 41]),
            ],
            // What the service answers for `after=2`, which is what the follower should now send.
            [
                roleEvent(3, 'task.created', ROLE_OTHER_SESSION, ['task_id' => 41]),
                roleEvent(4, 'task.created', ROLE_OTHER_SESSION, ['task_id' => 42]),
            ],
            // A caught-up page, whose only job is to be read with the cursor the page above
            // returned. Without a third read nothing here distinguishes the ordinary cursor
            // advance from the rewind, and deleting the advance passed every test.
            [],
        ],
        before: ['tasks:create'],
        after: ['tasks:create', 'coordinator:direct'],
    );

    $session = roleSession();
    $bridge = roleBridge($session);

    $bridge->run(roleStream(), tmpfile(), fn (string $m): null => null);

    // The directive was queued and the renewal still happened, which is the exit `tick()` takes
    // only when both are true at once.
    expect(roleWaitingIds())->toBe([1])
        ->and(roleRenewals())->toBe(1);

    $bridge->run(roleStream(), tmpfile(), fn (string $m): null => null);

    // Event 3 was below the promotion in page one and is delivered now, not lost.
    expect(roleWaitingIds())->toBe([1, 3, 4]);

    $bridge->run(roleStream(), tmpfile(), fn (string $m): null => null);

    // **Both cursor rules, asserted against what was actually sent.** `Http::sequence()` ignores
    // the query string, so the fixture's order would be satisfied by a follower that sent the same
    // cursor forever -- measured: deleting the line that advances it from the page passed all
    // fourteen tests before this read existed. `1` is the cursor the session started with, `2` is
    // the rewind to the role change, and `101` is page two's own cursor.
    expect(roleCursorsSent())->toBe(['1', '2', '101'])
        ->and(roleWaitingIds())->toBe([1, 3, 4]);
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

    $bridge = roleBridge($session);

    $bridge->run(roleStream(), tmpfile(), $record);
    $bridge->run(roleStream(), tmpfile(), $record);
    $bridge->run(roleStream(), tmpfile(), $record);

    // Said on the event and not on every tick: the cursor moves past it, so three passes produce
    // one line. A demotion that announced itself on every pass is the noise that stops stderr being
    // read at all.
    expect($said)->toHaveCount(1)
        // Positioned, and asserting the direction: the message must not tell a narrowed session it
        // is being widened, which is what "Renewing so it can act on it" did.
        ->and($said[0])->toContain('now in the `build` role')
        ->and($said[0])->toContain('from `coordinator`')
        ->and($said[0])->toContain('imposed by an administrator')
        ->and($session->allows('coordinator:direct'))->toBeFalse()
        // And it stops acting as one: both later `task.created` events are discarded, where before
        // the demotion they would have been queued for the agent.
        ->and(roleWaitingIds())->toBeEmpty();
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
        // Granted by a renewal that succeeded, so the assertion below bites: without it the ability
        // is absent under every implementation and the line proves nothing.
        after: ['tasks:create', 'coordinator:direct'],
        renewStatus: 500,
    );

    $session = roleSession();
    $said = [];
    $record = function (string $message) use (&$said): void {
        $said[] = $message;
    };

    // The same bridge both times, because `nextRenewAttempt` is its state and a second instance
    // would start with a clean one -- which is exactly the bug this asserts against.
    $bridge = roleBridge($session);

    $bridge->run(roleStream(), tmpfile(), $record);
    $bridge->run(roleStream(), tmpfile(), $record);

    expect(roleRenewals())->toBe(1)
        ->and($session->allows('coordinator:direct'))->toBeFalse()
        // And it says so, rather than failing silently.
        ->and(implode(' ', $said))->toContain('could not be renewed');
});

it('writes nothing to stdout on any of the paths that say something', function (): void {
    // All four messages this change can produce, in one run of the loop: the promotion, a failed
    // renewal, a refusal, and the demotion. The criterion is that EVERY one of them reaches the
    // diagnostic and none reaches stdout, and a test that exercised only the promotion was
    // asserting that about one of the four.
    roleService(
        pages: [
            [roleEvent(1, 'session.role_changed', ROLE_SESSION, ['from' => 'build', 'to' => 'coordinator', 'how' => 'approved'])],
            [roleEvent(2, 'session.role_requested', ROLE_SESSION, ['refused' => 'ci', 'stays' => 'coordinator'])],
            [roleEvent(3, 'session.role_changed', ROLE_SESSION, ['from' => 'coordinator', 'to' => 'build', 'how' => 'imposed'])],
        ],
        before: ['tasks:create'],
        after: ['tasks:create'],
        renewStatus: [500, 200],
    );

    $session = roleSession();
    $said = [];
    $record = function (string $message) use (&$said): void {
        $said[] = $message;
    };

    $out = tmpfile();
    $bridge = roleBridge($session, renewRetrySeconds: 0);

    $bridge->run(roleStream(), $out, $record);
    $bridge->run(roleStream(), $out, $record);
    $bridge->run(roleStream(), $out, $record);

    rewind($out);

    // Exactly the three protocol replies the three forwarded messages earned, and nothing any of
    // the four diagnostics said: a harness parses this stream, so a diagnostic here is a malformed
    // message rather than a visible error.
    expect((string) stream_get_contents($out))
        ->toBe(str_repeat('{"jsonrpc":"2.0","id":1,"result":{}}'."\n", 3));

    // The positive control for the assertion above. Without it, a run in which nothing was said at
    // all would satisfy it perfectly.
    expect(implode("\n", $said))
        ->toContain('now in the `coordinator` role')
        ->toContain('could not be renewed')
        ->toContain('for the `ci` role was refused')
        ->toContain('now in the `build` role');
});
