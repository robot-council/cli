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
 * @param  array<string, mixed>  $meta  The event's `meta`.
 * @return array<string, mixed> The event.
 */
function roleEvent(string $type, int $actor, array $meta): array
{
    return [
        'id' => random_int(1000, 9999),
        'type' => $type,
        'body' => 'a role moved',
        'meta' => $meta,
        'created_at' => '2026-09-24T02:00:00+00:00',
        'actor' => ['session_id' => $actor, 'github_login' => 'somedev', 'coordinator_direct' => false],
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
 * Fake a service whose feed answers one page, and whose renewal hands back new abilities.
 *
 * @param  list<array<string, mixed>>  $events  The page the feed answers with.
 * @param  list<string>  $before  The abilities the session starts with.
 * @param  list<string>  $after  The abilities a renewal returns.
 * @param  int  $expiresIn  How long the first token lasts; 3600 keeps it outside the renewal window.
 */
function roleService(array $events, array $before = [], array $after = [], int $expiresIn = 3600, int $renewStatus = 200): void
{
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
        '*/api/events*' => Http::response(['events' => $events, 'cursor' => 99], 200),
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
 * How many renewals were sent.
 */
function roleRenewals(): int
{
    return Http::recorded(
        fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/renew')
    )->count();
}

it('reports its own promotion and asks for a renewal', function (): void {
    roleService([roleEvent('session.role_changed', ROLE_SESSION, ['from' => 'build', 'to' => 'coordinator', 'how' => 'approved'])]);

    $said = [];
    $follower = new FleetFollower(roleSession(), ROLE_SERVICE, roleSink());

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
    // per administrator click.
    roleService([roleEvent('session.role_changed', ROLE_OTHER_SESSION, ['from' => 'build', 'to' => 'coordinator'])]);

    $said = [];
    $follower = new FleetFollower(roleSession(), ROLE_SERVICE, roleSink());

    $changed = $follower->tick(function (string $message) use (&$said): void {
        $said[] = $message;
    });

    expect($changed)->toBeFalse()
        ->and($said)->toBeEmpty();
});

it('reports a refusal, and does not renew for it', function (): void {
    // The service records a refusal against the request rather than as a role change, because no
    // role changed. Renewing would be a round trip to be told what this session already holds.
    roleService([roleEvent('session.role_requested', ROLE_SESSION, ['refused' => 'coordinator', 'stays' => 'build'])]);

    $said = [];
    $follower = new FleetFollower(roleSession(), ROLE_SERVICE, roleSink());

    $changed = $follower->tick(function (string $message) use (&$said): void {
        $said[] = $message;
    });

    expect($changed)->toBeFalse()
        ->and($said)->toHaveCount(1)
        ->and($said[0])->toContain('refused')
        ->and($said[0])->toContain('coordinator');
});

it('renews at once on a promotion, without waiting for the renewal window', function (): void {
    roleService(
        events: [roleEvent('session.role_changed', ROLE_SESSION, ['from' => 'build', 'to' => 'coordinator'])],
        before: ['tasks:create'],
        after: ['tasks:create', 'coordinator:direct'],
    );

    $session = roleSession();

    // The token has an hour left, so nothing about the schedule is due. Only the role change can
    // bring the renewal forward.
    expect($session->allows('coordinator:direct'))->toBeFalse();

    new Bridge($session, ROLE_SERVICE, Bridge::HEARTBEAT_SECONDS, new FleetFollower($session, ROLE_SERVICE, roleSink()))
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
        events: [roleEvent('task.created', ROLE_OTHER_SESSION, ['task_id' => 5])],
        before: ['tasks:create'],
    );

    $session = roleSession();

    new Bridge($session, ROLE_SERVICE, Bridge::HEARTBEAT_SECONDS, new FleetFollower($session, ROLE_SERVICE, roleSink()))
        ->run(roleStream(), tmpfile(), fn (string $m): null => null);

    expect(roleRenewals())->toBe(0);
});

it('backs off a renewal that fails rather than retrying it tightly', function (): void {
    roleService(
        events: [roleEvent('session.role_changed', ROLE_SESSION, ['from' => 'build', 'to' => 'coordinator'])],
        before: ['tasks:create'],
        renewStatus: 500,
    );

    $session = roleSession();
    $said = [];

    // Several messages, so the loop makes several passes and the feed is read more than once.
    $stream = tmpfile();
    fwrite($stream, str_repeat('{"jsonrpc":"2.0","id":1}'."\n", 5));
    rewind($stream);

    new Bridge($session, ROLE_SERVICE, Bridge::HEARTBEAT_SECONDS, new FleetFollower($session, ROLE_SERVICE, roleSink()))
        ->run($stream, tmpfile(), function (string $message) use (&$said): void {
            $said[] = $message;
        });

    // One attempt, then backed off for `RENEW_RETRY_SECONDS`. Without the backoff this retries on
    // every pass, which is the message rate.
    expect(roleRenewals())->toBe(1)
        ->and($session->allows('coordinator:direct'))->toBeFalse()
        // And it says so, rather than failing silently.
        ->and(implode(' ', $said))->toContain('could not be renewed');
});

it('writes nothing to stdout while doing any of this', function (): void {
    roleService(
        events: [roleEvent('session.role_changed', ROLE_SESSION, ['from' => 'build', 'to' => 'coordinator'])],
        before: ['tasks:create'],
        after: ['tasks:create', 'coordinator:direct'],
    );

    $out = tmpfile();

    new Bridge(roleSession(), ROLE_SERVICE, Bridge::HEARTBEAT_SECONDS, new FleetFollower(roleSession(), ROLE_SERVICE, roleSink()))
        ->run(roleStream(), $out, fn (string $m): null => null);

    rewind($out);
    $written = (string) stream_get_contents($out);

    // Exactly the one protocol reply the forwarded message earned, and nothing the role change
    // said: a harness parses this stream, so a diagnostic here is a malformed message.
    expect($written)->toBe('{"jsonrpc":"2.0","id":1,"result":{}}'."\n");
});
