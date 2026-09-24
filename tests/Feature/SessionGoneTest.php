<?php

declare(strict_types=1);

/**
 * What a bridge does when the fleet's presence sweep marks its own session `gone`.
 *
 * **`gone` is final, and that is the whole argument.** `robot-council/core`'s `Support\SessionPresence`
 * refuses the session's tokens, releases its claims and drops its locks, so from that moment every
 * request the bridge makes fails and every tool call it forwards fails with it. Before #165 the
 * loop carried on: each message took the 401 path, renewed, retried, and reported that the
 * *installation* may be revoked -- naming the one thing that was fine.
 *
 * **`stale` is the opposite and is asserted here, not assumed.** A stale session "still holds what
 * it claimed and is active again on its next request", so a bridge that ended on it would be ending
 * a session about to recover.
 *
 * @command  vendor/bin/pest --compact tests/Feature/SessionGoneTest.php
 */

use App\Support\Bridge;
use App\Support\Credentials\Credential;
use App\Support\FleetFollower;
use App\Support\PendingEvents;
use App\Support\Session;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const GONE_SERVICE = 'https://gone.example.test';
const GONE_INSTALLATION = 'rcouncil_1|GONE-INSTALLATION';
const GONE_SESSION = 21;
const GONE_OTHER_SESSION = 34;

beforeEach(function (): void {
    Http::preventStrayRequests();

    $this->stateDirectory = sys_get_temp_dir().'/rc-gone-'.bin2hex(random_bytes(6));

    putenv('XDG_STATE_HOME='.$this->stateDirectory);
});

afterEach(function (): void {
    goneSink()->forget();

    array_map(unlink(...), glob($this->stateDirectory.'/robot-council/pending/*') ?: []);
    @rmdir($this->stateDirectory.'/robot-council/pending');
    @rmdir($this->stateDirectory.'/robot-council');
    @rmdir($this->stateDirectory);

    putenv('XDG_STATE_HOME');
});

function goneSink(): PendingEvents
{
    return new PendingEvents(GONE_SERVICE, 'claude', 'gone');
}

/**
 * One feed event in the shape `Support\FleetFeed::describe()` serializes.
 *
 * A presence event's `actor.session_id` is the session it is **about**: `Support\SessionPresence`
 * records it against that session, and the sweep contributes no session id anywhere.
 *
 * @param  array<string, mixed>  $meta  The event's `meta`.
 * @return array<string, mixed> The event.
 */
function goneEvent(int $id, string $type, int $actor, array $meta = []): array
{
    return [
        'id' => $id,
        'type' => $type,
        'body' => 'the sweep decided something',
        'meta' => ['installation_id' => 9, ...$meta],
        'created_at' => '2026-09-24T09:00:00+00:00',
        'actor' => ['session_id' => $actor, 'github_login' => 'somedev', 'coordinator_direct' => false],
        'performed_by' => null,
    ];
}

/**
 * A stream carrying `$messages` protocol messages, which is what gives the loop passes to work in.
 *
 * @return resource
 */
function goneStream(int $messages = 1)
{
    $stream = tmpfile();

    fwrite($stream, str_repeat('{"jsonrpc":"2.0","id":1}'."\n", $messages));
    rewind($stream);

    return $stream;
}

/**
 * Fake a service whose feed answers the given pages in order.
 *
 * @param  list<list<array<string, mixed>>>  $pages  A page per feed read.
 * @param  list<string>  $abilities  What the session may do.
 */
function goneService(array $pages, array $abilities = ['tasks:create'], int $expiresIn = 3600): void
{
    $feed = Http::sequence();

    foreach ($pages as $index => $events) {
        $feed->push(['events' => $events, 'cursor' => 200 + $index], 200);
    }

    $feed->whenEmpty(Http::response(['events' => [], 'cursor' => 999], 200));

    Http::fake([
        '*/api/sessions/'.GONE_SESSION.'/renew' => Http::response(
            ['token' => 'rcouncil_2|NEW', 'expires_in' => 3600, 'abilities' => $abilities], 200
        ),
        '*/api/sessions' => Http::response([
            'session_id' => GONE_SESSION,
            'token' => 'rcouncil_2|FIRST',
            'expires_in' => $expiresIn,
            'feed_cursor' => 1,
            'abilities' => $abilities,
        ], 201),
        // **One `Http::fake()` call, and the feed stub is the sequence itself.** Successive calls
        // append stubs and the FIRST match wins, so a catch-all registered here and a sequence
        // registered afterwards means the sequence never runs -- measured: every page came back
        // empty and the tests failed on a diagnostic that was never produced.
        '*/api/events*' => $feed,
        '*/api/agent/heartbeat' => Http::response(['ok' => true], 200),
        '*/api/mcp' => Http::response('{"jsonrpc":"2.0","id":1,"result":{}}', 200),
    ]);
}

function goneSession(): Session
{
    $session = new Session(app(Factory::class), GONE_SERVICE, new Credential(GONE_INSTALLATION));

    $session->start('gone');

    return $session;
}

/**
 * A follower that reads the feed on every tick.
 */
function goneFollower(Session $session): FleetFollower
{
    return new FleetFollower($session, GONE_SERVICE, goneSink(), 0);
}

/**
 * How many MCP messages were forwarded.
 */
function goneForwarded(): int
{
    return Http::recorded(
        fn (Request $request): bool => str_ends_with($request->url(), '/api/mcp')
    )->count();
}

it('reports its own session being marked gone, and says what caused it', function (): void {
    goneService([[goneEvent(1, 'session.gone', GONE_SESSION)]]);

    $said = [];
    $session = goneSession();

    new Bridge($session, GONE_SERVICE, Bridge::HEARTBEAT_SECONDS, goneFollower($session))
        ->run(goneStream(), tmpfile(), function (string $message) use (&$said): void {
            $said[] = $message;
        });

    $all = implode("\n", $said);

    // The cause is the fleet's decision. Before this, the only thing an operator heard was
    // `Bridge::forward()`'s 401 backstop saying the installation may have been revoked -- which
    // sends them to look at their enrollment, the one thing that is fine.
    expect($all)->toContain('marked this session gone')
        ->and($all)->toContain('claims and locks have been released')
        ->and($all)->not->toContain('installation may have been revoked')
        ->and($all)->not->toContain('credential');
});

it('stops the loop, so a later pass forwards nothing', function (): void {
    // **Stopping is not observable within one `run()`, which is why this runs it twice.** A
    // seekable stream hands `fread` every message at once, so they are all forwarded before
    // `periodic()` is reached -- an assertion on that count is true whether or not the loop stops.
    // What `stop()` actually buys is that `while (! $this->stopping)` refuses the next pass, and
    // the second `run()` below is where that shows.
    goneService([[goneEvent(1, 'session.gone', GONE_SESSION)]]);

    $session = goneSession();
    $bridge = new Bridge($session, GONE_SERVICE, Bridge::HEARTBEAT_SECONDS, goneFollower($session));

    $bridge->run(goneStream(), tmpfile(), fn (string $m): null => null);

    expect(goneForwarded())->toBe(1);

    // The same bridge, a fresh message, and a feed that would say nothing new. A bridge that had
    // not stopped forwards it.
    $bridge->run(goneStream(), tmpfile(), fn (string $m): null => null);

    expect(goneForwarded())->toBe(1);
});

it('does not renew a session the fleet has discarded', function (): void {
    // **The token has to be inside the renewal window, or this proves nothing.** With an hour
    // left the renewal condition is false anyway and the assertion holds whether or not the early
    // return exists -- measured: deleting the return passed this test before `expiresIn` was
    // passed here.
    goneService(
        pages: [[goneEvent(1, 'session.gone', GONE_SESSION)]],
        expiresIn: Bridge::RENEW_WITHIN_SECONDS - 1,
    );

    $session = goneSession();

    new Bridge($session, GONE_SERVICE, Bridge::HEARTBEAT_SECONDS, goneFollower($session))
        ->run(goneStream(), tmpfile(), fn (string $m): null => null);

    expect(Http::recorded(
        fn (Request $request): bool => str_ends_with($request->url(), '/renew')
    )->count())->toBe(0);
});

it('ignores a session.gone that names another session', function (): void {
    // The negative control. Another session going gone is ordinary fleet news, and a bridge that
    // ended on it would end whenever anybody else's agent was swept.
    goneService([[goneEvent(1, 'session.gone', GONE_OTHER_SESSION)]]);

    $said = [];
    $session = goneSession();
    $follower = goneFollower($session);

    new Bridge($session, GONE_SERVICE, Bridge::HEARTBEAT_SECONDS, $follower)
        ->run(goneStream(), tmpfile(), function (string $message) use (&$said): void {
            $said[] = $message;
        });

    expect($follower->sessionHasGone())->toBeFalse()
        ->and(implode("\n", $said))->not->toContain('marked this session gone');
});

it('does not end on its own session.stale, which is recoverable', function (): void {
    // Asserted rather than assumed, because the two arrive by the same path and differ only in
    // their type string. A bridge that ended on `stale` would end a session about to recover on its
    // next request.
    goneService([[goneEvent(1, 'session.stale', GONE_SESSION, ['quiet_since' => '2026-09-24T08:55:00+00:00'])]]);

    $said = [];
    $session = goneSession();
    $follower = goneFollower($session);

    new Bridge($session, GONE_SERVICE, Bridge::HEARTBEAT_SECONDS, $follower)
        ->run(goneStream(), tmpfile(), function (string $message) use (&$said): void {
            $said[] = $message;
        });

    expect($follower->sessionHasGone())->toBeFalse()
        ->and(implode("\n", $said))->not->toContain('marked this session gone');
});

it('still delivers the gone event to the agent, rather than only acting on it', function (): void {
    // #158 made this reach the sink; ending the bridge must not take it away. The agent's own
    // record of why its session stopped is the sink entry, not the stderr line.
    goneService([[goneEvent(1, 'session.gone', GONE_SESSION)]]);

    $session = goneSession();

    new Bridge($session, GONE_SERVICE, Bridge::HEARTBEAT_SECONDS, goneFollower($session))
        ->run(goneStream(), tmpfile(), fn (string $m): null => null);

    $waiting = goneSink()->peek();

    expect($waiting)->toHaveCount(1)
        ->and($waiting[0]['type'] ?? null)->toBe('session.gone');
});

it('writes nothing to stdout while ending', function (): void {
    goneService([[goneEvent(1, 'session.gone', GONE_SESSION)]]);

    $session = goneSession();
    $out = tmpfile();

    new Bridge($session, GONE_SERVICE, Bridge::HEARTBEAT_SECONDS, goneFollower($session))
        ->run(goneStream(), $out, fn (string $m): null => null);

    rewind($out);

    // Exactly the one protocol reply the forwarded message earned. A harness parses this stream, so
    // the shutdown notice would be a malformed message rather than a visible error.
    expect((string) stream_get_contents($out))->toBe('{"jsonrpc":"2.0","id":1,"result":{}}'."\n");
});

it('ends a session the service has already discarded without a second error', function (): void {
    // `McpCommand`'s `finally` ends the session whatever stopped the loop, and the service will
    // refuse that call for a session it has dropped. `Session::end()` swallows it deliberately;
    // this asserts that rather than assuming it, because a second failure on the way out is exactly
    // what an operator does not need after being told the first one.
    goneService([[goneEvent(1, 'session.gone', GONE_SESSION)]]);

    $session = goneSession();

    expect($session->id())->toBe(GONE_SESSION);

    // **A refusal that actually throws.** A 404 response is not a `Throwable` in Laravel's HTTP
    // client unless `->throw()` is called, so faking one exercises no `catch` at all -- measured:
    // making `Session::end()` rethrow instead of swallowing passed this test before this line
    // raised a connection failure instead.
    Http::fake(['*/api/sessions/'.GONE_SESSION => fn (): never => throw new ConnectionException('the fleet dropped this session')]);

    $session->end();

    // It did not throw -- reaching this line is that assertion -- and it finished the job anyway:
    // the session is forgotten locally, so nothing afterwards tries to act as it.
    expect($session->id())->toBeNull();
});
