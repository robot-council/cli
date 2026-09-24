<?php

declare(strict_types=1);

/**
 * What a bridge does when the fleet's presence sweep marks its own session `gone`.
 *
 * **`gone` is final.** `robot-council/core`'s `Support\SessionPresence` refuses the session's
 * tokens, releases its claims and drops its locks, and nothing lifts it: `movesFrom(Active)` is
 * `[Stale]`, so `resume()` can never reach a gone row. From that moment every request the bridge
 * makes fails and every tool call it forwards fails with it, and before #165 it carried on making
 * them.
 *
 * **The feed cannot carry this news, and that is why these tests look the way they do.** The first
 * attempt at #165 watched for a `session.gone` naming this session in the feed. It can never
 * arrive: core writes the event, flips the status and deletes the tokens in one `DB::transaction`,
 * and `Http\Middleware\EnsureAgentSession` refuses a gone session outright -- `GET events` sits
 * behind it. So the feed read is a 401 from the instant the event exists, and a test faking a 200
 * with that event in it asserts against a response the service cannot produce for the session the
 * event is about.
 *
 * What does reach the client is the renewal endpoint, which takes the **installation** credential
 * rather than the session token and so still answers: core's `SessionRenewController` returns `409`
 * for exactly and only the gone case. Two things lead there -- a tool call refused with 401, and a
 * heartbeat refused with 401 -- and both are exercised here.
 *
 * @command  vendor/bin/pest --compact tests/Feature/SessionGoneTest.php
 */

use App\Support\Bridge;
use App\Support\Credentials\Credential;
use App\Support\FleetFollower;
use App\Support\PendingEvents;
use App\Support\Session;
use App\Support\SessionHasGone;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const GONE_SERVICE = 'https://gone.example.test';
const GONE_INSTALLATION = 'rcouncil_1|GONE-INSTALLATION';
const GONE_SESSION = 21;

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
 * A stream carrying `$messages` protocol messages.
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
 * A stream that gives the loop one pass and forwards nothing.
 *
 * **A blank line rather than an empty file, and the difference is the whole test.** `run()` returns
 * at `feof` *before* `periodic()`, so an empty stream never reaches the periodic work at all. One
 * newline is read, trimmed to an empty line and skipped, so the loop arrives at `periodic()` having
 * forwarded nothing -- which is what an idle bridge looks like.
 *
 * @return resource
 */
function goneIdleStream()
{
    $stream = tmpfile();

    fwrite($stream, "\n");
    rewind($stream);

    return $stream;
}

/**
 * Fake the service as it behaves for a session in a given state.
 *
 * **Modelled on what core answers, not on what is convenient to assert.** Once a session has gone,
 * everything taking its token answers 401 (`EnsureAgentSession`), and only the renewal endpoint
 * still answers, with 409 (`SessionRenewController`).
 *
 * @param  int  $renewStatus  What the renewal answers. 409 is core's gone case.
 * @param  int  $sessionTokenStatus  What endpoints taking the session token answer. 401 once gone.
 * @param  int  $expiresIn  How long the first token lasts.
 */
function goneService(int $renewStatus = 200, int $sessionTokenStatus = 200, int $expiresIn = 3600): void
{
    Http::fake([
        '*/api/sessions/'.GONE_SESSION.'/renew' => Http::response(
            ['token' => 'rcouncil_2|NEW', 'expires_in' => 3600, 'abilities' => ['tasks:create']],
            $renewStatus
        ),
        '*/api/sessions' => Http::response([
            'session_id' => GONE_SESSION,
            'token' => 'rcouncil_2|FIRST',
            'expires_in' => $expiresIn,
            'feed_cursor' => 1,
            'abilities' => ['tasks:create'],
        ], 201),
        '*/api/events*' => Http::response(['events' => [], 'cursor' => 999], $sessionTokenStatus),
        '*/api/agent/heartbeat' => Http::response(['ok' => true], $sessionTokenStatus),
        '*/api/mcp' => $sessionTokenStatus === 200
            ? Http::response('{"jsonrpc":"2.0","id":1,"result":{}}', 200)
            : Http::response('', $sessionTokenStatus),
    ]);
}

function goneSession(): Session
{
    $session = new Session(app(Factory::class), GONE_SERVICE, new Credential(GONE_INSTALLATION));

    $session->start('gone');

    return $session;
}

/**
 * How many renewals were attempted.
 */
function goneRenewals(): int
{
    return Http::recorded(
        fn (Request $request): bool => str_ends_with($request->url(), '/renew')
    )->count();
}

/**
 * How many feed reads were sent.
 */
function goneFeedReads(): int
{
    return Http::recorded(
        fn (Request $request): bool => str_contains($request->url(), '/api/events')
    )->count();
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

it('tells a 409 from an ordinary renewal failure', function (): void {
    // The distinction the whole change rests on. A 500 is "try again in a moment"; a 409 is "there
    // is nothing to try". `Session::renew()` collapsed both into one message.
    goneService(renewStatus: 409);

    $session = goneSession();

    expect(fn () => $session->renew())->toThrow(SessionHasGone::class);
});

it('does not mistake an ordinary renewal failure for the session being gone', function (): void {
    // The negative control for the test above. Without it, a `renew()` that threw `SessionHasGone`
    // on every failure would pass just as well -- and a bridge would end itself over a bad minute.
    goneService(renewStatus: 500);

    $session = goneSession();

    expect(fn () => $session->renew())->not->toThrow(SessionHasGone::class);
});

it('reports a refused heartbeat rather than swallowing it', function (): void {
    // A 401 does not throw in this client, so the old `catch (Throwable)` never saw one and the
    // caller could not tell a delivered heartbeat from a rejected one.
    goneService(sessionTokenStatus: 401);

    expect(goneSession()->heartbeat())->toBeFalse();
});

it('treats a heartbeat that could not be sent as saying nothing', function (): void {
    // An unsent heartbeat is not evidence about the session, and reading it as one would end a
    // bridge over a dropped connection.
    Http::fake([
        '*/api/sessions' => Http::response([
            'session_id' => GONE_SESSION, 'token' => 'rcouncil_2|FIRST',
            'expires_in' => 3600, 'feed_cursor' => 1, 'abilities' => [],
        ], 201),
        '*/api/agent/heartbeat' => fn (): never => throw new ConnectionException('offline'),
    ]);

    expect(goneSession()->heartbeat())->toBeTrue();
});

it('ends when a tool call is refused and the renewal says the session has gone', function (): void {
    // The busy bridge's path: a forwarded message 401s, the existing 401 backstop renews, and the
    // renewal answers 409.
    goneService(renewStatus: 409, sessionTokenStatus: 401);

    $said = [];
    $session = goneSession();
    $bridge = new Bridge($session, GONE_SERVICE, Bridge::HEARTBEAT_SECONDS);

    $bridge->run(goneStream(), tmpfile(), function (string $message) use (&$said): void {
        $said[] = $message;
    });

    $all = implode("\n", $said);

    expect($all)->toContain('marked this session gone')
        ->and($all)->toContain('claims and locks have been released')
        // The message it replaces named a status code rather than a cause.
        ->and($all)->not->toContain('HTTP 409')
        // And not the one that would send an operator to inspect their enrollment.
        ->and($all)->not->toContain('installation may have been revoked');

    // Stopped: a second pass on the same bridge forwards nothing, because `stop()` set `$stopping`
    // and `while (! $this->stopping)` refuses the next pass.
    $bridge->run(goneStream(), tmpfile(), fn (string $m): null => null);

    expect(goneForwarded())->toBe(1);
});

it('ends an idle bridge too, from a refused heartbeat', function (): void {
    // **The path that matters for a bridge nobody is talking to.** It makes no tool calls, and its
    // token may be an hour from the renewal window, so without this it would sit failing quietly
    // until the token aged out. A heartbeat interval of 0 makes the first pass due, which is what
    // that parameter exists for.
    goneService(renewStatus: 409, sessionTokenStatus: 401);

    $said = [];
    $session = goneSession();

    new Bridge($session, GONE_SERVICE, 0)
        ->run(goneIdleStream(), tmpfile(), function (string $message) use (&$said): void {
            $said[] = $message;
        });

    // **Nothing was forwarded, which is what makes this the heartbeat's path and not the tool
    // call's.** With a message in the stream this test passed with the heartbeat-to-renewal link
    // deleted, because the 401 on that forwarded call reached the same renewal by another road.
    expect(goneForwarded())->toBe(0)
        ->and(implode("\n", $said))->toContain('marked this session gone')
        ->and(goneRenewals())->toBeGreaterThan(0);
});

it('does not end a bridge whose heartbeat was refused but whose renewal succeeded', function (): void {
    // The negative control for the heartbeat path, and the reason a refusal only *asks* a question
    // rather than answering one. A token revoked and reissued is refused once and renews fine;
    // ending there would kill a session that is still alive.
    goneService(renewStatus: 200, sessionTokenStatus: 401);

    $said = [];
    $session = goneSession();
    $bridge = new Bridge($session, GONE_SERVICE, 0);

    $bridge->run(goneStream(), tmpfile(), fn (string $m): null => null);

    expect(goneRenewals())->toBeGreaterThan(0);

    $bridge->run(goneStream(), tmpfile(), function (string $message) use (&$said): void {
        $said[] = $message;
    });

    // Not stopped: the second pass still ran.
    expect(goneForwarded())->toBeGreaterThan(1)
        ->and(implode("\n", $said))->not->toContain('marked this session gone');
});

it('writes nothing to stdout while ending', function (): void {
    goneService(renewStatus: 409, sessionTokenStatus: 401);

    $session = goneSession();
    $out = tmpfile();

    new Bridge($session, GONE_SERVICE, 0)
        ->run(goneStream(), $out, fn (string $m): null => null);

    rewind($out);

    // Nothing at all: the message was refused, so there was no protocol reply to write, and the
    // shutdown notice went to the diagnostic. A harness parses this stream.
    expect((string) stream_get_contents($out))->toBeEmpty();
});

it('ends a session the service has already discarded without a second error', function (): void {
    // `McpCommand`'s `finally` ends the session whatever stopped the loop. `Session::end()` swallows
    // a failure deliberately; asserted rather than assumed, because a second failure on the way out
    // is exactly what an operator does not need after being told the first one.
    goneService();

    $session = goneSession();

    expect($session->id())->toBe(GONE_SESSION);

    // A refusal that actually throws. A 404 response is not a `Throwable` in this client unless
    // `->throw()` is called, so faking one exercises no `catch` at all.
    Http::fake(['*/api/sessions/'.GONE_SESSION => fn (): never => throw new ConnectionException('the fleet dropped this session')]);

    $session->end();

    expect($session->id())->toBeNull();
});

it('leaves an ordinary run ordinary, with a follower attached', function (): void {
    // The gone path is reached with no follower at all, which is how the tests above are written.
    // This is the other half: a live session with a follower still forwards and does not renew.
    goneService();

    $session = goneSession();

    new Bridge($session, GONE_SERVICE, Bridge::HEARTBEAT_SECONDS, new FleetFollower($session, GONE_SERVICE, goneSink(), 0))
        ->run(goneStream(), tmpfile(), fn (string $m): null => null);

    expect(goneForwarded())->toBe(1)
        ->and(goneRenewals())->toBe(0);
});

it('says why the session ended, and does not blame the feed on the way', function (): void {
    // **Measured before the fix, with a follower attached**: the operator got two lines, and the
    // uninformative one came first --
    //
    //   [1] Could not read the fleet feed: the service answered 401.
    //   [2] The fleet has marked this session gone, so its claims and locks have been released...
    //
    // A 401 on the feed is not a feed problem. It says the session token is not honored, which the
    // feed cannot explain and the renewal can (#169).
    goneService(renewStatus: 409, sessionTokenStatus: 401);

    $said = [];
    $session = goneSession();

    new Bridge($session, GONE_SERVICE, 0, new FleetFollower($session, GONE_SERVICE, goneSink(), 0))
        ->run(goneIdleStream(), tmpfile(), function (string $message) use (&$said): void {
            $said[] = $message;
        });

    expect($said)->toHaveCount(1)
        ->and($said[0])->toContain('marked this session gone')
        ->and($said[0])->not->toContain('Could not read the fleet feed');
});

it('still reports a feed failure that is not a refusal', function (): void {
    // The control for the line above. Suppressing every read failure, rather than the 401, would
    // leave a bridge silent about a service that is genuinely broken.
    goneService(renewStatus: 200, sessionTokenStatus: 500);

    $said = [];
    $session = goneSession();

    new Bridge($session, GONE_SERVICE, Bridge::HEARTBEAT_SECONDS, new FleetFollower($session, GONE_SERVICE, goneSink(), 0))
        ->run(goneIdleStream(), tmpfile(), function (string $message) use (&$said): void {
            $said[] = $message;
        });

    expect(implode('
', $said))->toContain('Could not read the fleet feed')
        ->and(implode('
', $said))->toContain('500');
});

it('turns a refused feed read into a renewal, so an idle bridge learns from it', function (): void {
    // The feed polls every `POLL_SECONDS` where the heartbeat is a minute apart, so on a real
    // bridge this is the earlier of the two signals. With the heartbeat interval left at its
    // default, only the feed can produce the renewal here.
    goneService(renewStatus: 409, sessionTokenStatus: 401);

    $said = [];
    $session = goneSession();

    new Bridge($session, GONE_SERVICE, Bridge::HEARTBEAT_SECONDS, new FleetFollower($session, GONE_SERVICE, goneSink(), 0))
        ->run(goneIdleStream(), tmpfile(), function (string $message) use (&$said): void {
            $said[] = $message;
        });

    expect(goneRenewals())->toBe(1)
        ->and(implode('
', $said))->toContain('marked this session gone');
});

it('does not end a bridge whose feed was refused but whose renewal succeeded', function (): void {
    // A token revoked and reissued is refused once and renews fine. The refusal asks a question;
    // it does not answer one.
    goneService(renewStatus: 200, sessionTokenStatus: 401);

    $said = [];
    $session = goneSession();
    $bridge = new Bridge($session, GONE_SERVICE, Bridge::HEARTBEAT_SECONDS, new FleetFollower($session, GONE_SERVICE, goneSink(), 0));

    $bridge->run(goneIdleStream(), tmpfile(), fn (string $m): null => null);

    expect(goneRenewals())->toBe(1);

    $bridge->run(goneStream(), tmpfile(), function (string $message) use (&$said): void {
        $said[] = $message;
    });

    expect(goneForwarded())->toBeGreaterThan(0)
        ->and(implode('
', $said))->not->toContain('marked this session gone');
});

it('stops asking for a renewal once the feed is accepted again', function (): void {
    // **The flag describes the last attempt, not the worst one.** Left sticky it would set
    // `renewalDue` on every later pass, and a bridge that recovered from one refused read would
    // renew every pass for the rest of its life -- bounded only by the retry floor, which this test
    // collapses to zero so the difference is visible at all.
    $feed = Http::sequence()
        ->push(['events' => [], 'cursor' => 1], 401)
        ->push(['events' => [], 'cursor' => 2], 200);

    $feed->whenEmpty(Http::response(['events' => [], 'cursor' => 3], 200));

    Http::fake([
        '*/api/sessions/'.GONE_SESSION.'/renew' => Http::response(
            ['token' => 'rcouncil_2|NEW', 'expires_in' => 3600, 'abilities' => []], 200
        ),
        '*/api/sessions' => Http::response([
            'session_id' => GONE_SESSION, 'token' => 'rcouncil_2|FIRST',
            'expires_in' => 3600, 'feed_cursor' => 1, 'abilities' => [],
        ], 201),
        '*/api/events*' => $feed,
        '*/api/agent/heartbeat' => Http::response(['ok' => true], 200),
        '*/api/mcp' => Http::response('{"jsonrpc":"2.0","id":1,"result":{}}', 200),
    ]);

    $session = goneSession();

    // **A zero backoff, or the later reads never happen.** The failure path schedules the next poll
    // from `$backoff`, not from `$pollSeconds`, so with the default the runs below return at the
    // guard and the sequence's 200 is never consumed -- the test would then pass unchanged if every
    // read were refused, which is the opposite of what it claims.
    $bridge = new Bridge(
        $session, GONE_SERVICE, Bridge::HEARTBEAT_SECONDS,
        new FleetFollower($session, GONE_SERVICE, goneSink(), 0, 0), 0
    );

    $bridge->run(goneIdleStream(), tmpfile(), fn (string $m): null => null);

    expect(goneRenewals())->toBe(1);

    $bridge->run(goneIdleStream(), tmpfile(), fn (string $m): null => null);
    $bridge->run(goneIdleStream(), tmpfile(), fn (string $m): null => null);

    // The reads actually happened, so "accepted again" is a state this test reached rather than a
    // fixture it merely declared.
    expect(goneFeedReads())->toBeGreaterThan(1)
        // Still one renewal: the later reads were accepted, so nothing asked again.
        ->and(goneRenewals())->toBe(1);
});

it('says so when the feed keeps refusing and the renewal keeps succeeding', function (): void {
    // **The hole suppression would otherwise open.** Every 401 branch of `EnsureAgentSession` is
    // either repaired by the renewal or reported by it -- except a feed that goes on refusing while
    // the renewal goes on working, which a proxy in front of the service can produce. Silence there
    // is a bridge that never reads the fleet again and never mentions it. The first refusal is
    // quiet, because the renewal usually does explain it; the rest are not.
    goneService(renewStatus: 200, sessionTokenStatus: 401);

    $said = [];
    $session = goneSession();
    // Zero backoff, so the second refusal is reachable without sitting through `BACKOFF_SECONDS`.
    $follower = new FleetFollower($session, GONE_SERVICE, goneSink(), 0, 0);
    $record = function (string $message) use (&$said): void {
        $said[] = $message;
    };

    $follower->tick($record);

    expect($said)->toBeEmpty();

    $follower->tick($record);
    $follower->tick($record);

    expect(implode('
', $said))->toContain('refused this session')
        ->and(implode('
', $said))->toContain('2 times in a row')
        // **It claims nothing it cannot see.** `FleetFollower` neither performs nor observes a
        // renewal, and `robot-council/core` puts its MCP endpoint behind the same guard as the
        // feed -- so a message promising that tool calls are fine would be wrong in the ordinary
        // case, which is the defect this ticket exists to remove.
        ->and(implode('
', $said))->not->toContain('renewing has not fixed it')
        ->and(implode('
', $said))->not->toContain('Tool calls are unaffected');
});

it('starts the refusal count over once a read succeeds', function (): void {
    // Refused, refused, accepted, refused. The count must restart, or a bridge that recovered and
    // then hit one more refusal would announce it as though the run had never stopped -- and the
    // reset line was covered by nothing.
    $feed = Http::sequence()
        ->push(['events' => [], 'cursor' => 1], 401)
        ->push(['events' => [], 'cursor' => 1], 401)
        ->push(['events' => [], 'cursor' => 2], 200)
        ->push(['events' => [], 'cursor' => 2], 401);

    $feed->whenEmpty(Http::response(['events' => [], 'cursor' => 3], 200));

    Http::fake([
        '*/api/sessions' => Http::response([
            'session_id' => GONE_SESSION, 'token' => 'rcouncil_2|FIRST',
            'expires_in' => 3600, 'feed_cursor' => 1, 'abilities' => [],
        ], 201),
        '*/api/events*' => $feed,
    ]);

    $said = [];
    $record = function (string $message) use (&$said): void {
        $said[] = $message;
    };

    $follower = new FleetFollower(goneSession(), GONE_SERVICE, goneSink(), 0, 0);

    $follower->tick($record);   // refused, quiet
    $follower->tick($record);   // refused again, says "2 times"
    $follower->tick($record);   // accepted, resets
    $follower->tick($record);   // refused once more, quiet again

    expect(goneFeedReads())->toBe(4)
        ->and($said)->toHaveCount(1)
        ->and($said[0])->toContain('2 times in a row');
});
