<?php

declare(strict_types=1);

/**
 * The stdio bridge: what it forwards, what it renews, and what it is allowed to print.
 *
 * Driven through ordinary streams rather than the real `STDIN`, which a test process does not own.
 *
 * **The sharpest assertion here is that every line on stdout parses as a protocol message.** A
 * harness parses that stream, so a stray line is not a visible error -- it is a malformed message,
 * on whichever machine has a slightly different PHP configuration. Asserting "no token on stdout"
 * would not catch a warning nobody predicted; asserting that the whole stream parses does.
 *
 * @command  vendor/bin/pest --compact tests/Feature/BridgeTest.php
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

const BRIDGE_SERVICE = 'https://fleet.example.test';
const BRIDGE_INSTALLATION = 'rcouncil_1|BRIDGE-INSTALLATION-0123456789';
const FIRST_TOKEN = 'rcouncil_2|FIRST-SESSION-TOKEN-abcdef';
const SECOND_TOKEN = 'rcouncil_3|RENEWED-SESSION-TOKEN-abcdef';

/**
 * A stream holding the given text, positioned at the start.
 *
 * **`tmpfile()` rather than `php://memory`, and that is load-bearing.** The bridge waits on
 * `stream_select`, which needs a real file descriptor. A memory stream has none, so PHP strips it
 * from the array and then raises `ValueError: No stream arrays were passed` -- measured. A real
 * temporary file is also the more faithful stand-in for the stdin a harness hands over.
 *
 * @return resource The stream.
 */
function streamOf(string $contents)
{
    $stream = tmpfile();

    fwrite($stream, $contents);
    rewind($stream);

    return $stream;
}

/**
 * A started session against the fake service.
 */
function startedSession(int $expiresIn = 3600): Session
{
    $session = new Session(app(Factory::class), BRIDGE_SERVICE, new Credential(BRIDGE_INSTALLATION));

    $session->start();

    return $session;
}

beforeEach(function (): void {
    Http::preventStrayRequests();
});

it('forwards a message and writes the response back', function (): void {
    Http::fake([
        '*/api/sessions' => Http::response(['session_id' => 7, 'token' => FIRST_TOKEN, 'expires_in' => 3600], 201),
        '*/api/mcp' => Http::response('{"jsonrpc":"2.0","id":1,"result":{"tools":[]}}', 200),
    ]);

    $out = tmpfile();

    new Bridge(startedSession(), BRIDGE_SERVICE)
        ->run(streamOf("{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/list\"}\n"), $out, fn (string $m): null => null);

    rewind($out);

    expect(trim((string) stream_get_contents($out)))->toBe('{"jsonrpc":"2.0","id":1,"result":{"tools":[]}}');
});

it('carries the session token, never the installation credential', function (): void {
    Http::fake([
        '*/api/sessions' => Http::response(['session_id' => 7, 'token' => FIRST_TOKEN, 'expires_in' => 3600], 201),
        '*/api/mcp' => Http::response('{"jsonrpc":"2.0","id":1,"result":{}}', 200),
    ]);

    $out = tmpfile();

    new Bridge(startedSession(), BRIDGE_SERVICE)
        ->run(streamOf("{\"jsonrpc\":\"2.0\",\"id\":1}\n"), $out, fn (string $m): null => null);

    Http::assertSent(function (Request $request): bool {
        if (str_contains($request->url(), '/api/mcp')) {
            // The two-key shape #15 decided, exercised rather than assumed
            expect($request->hasHeader('Authorization', 'Bearer '.FIRST_TOKEN))->toBeTrue();
        }

        return true;
    });
});

it('renews once and retries after a 401, without restarting', function (): void {
    Http::fake([
        '*/api/sessions/7/renew' => Http::response(['token' => SECOND_TOKEN, 'expires_in' => 3600], 200),
        '*/api/sessions' => Http::response(['session_id' => 7, 'token' => FIRST_TOKEN, 'expires_in' => 3600], 201),
        '*/api/mcp' => Http::sequence()
            ->push(['error' => 'Unauthenticated.'], 401)
            ->push('{"jsonrpc":"2.0","id":1,"result":{"ok":true}}', 200),
    ]);

    $out = tmpfile();

    new Bridge(startedSession(), BRIDGE_SERVICE)
        ->run(streamOf("{\"jsonrpc\":\"2.0\",\"id\":1}\n"), $out, fn (string $m): null => null);

    rewind($out);

    // The call succeeded after an automatic renewal, in the same process
    expect(trim((string) stream_get_contents($out)))->toBe('{"jsonrpc":"2.0","id":1,"result":{"ok":true}}');

    $renewed = 0;
    $retriedWithNewToken = false;

    Http::assertSent(function (Request $request) use (&$renewed, &$retriedWithNewToken): bool {
        if (str_contains($request->url(), '/renew')) {
            $renewed++;
        }

        if (str_contains($request->url(), '/api/mcp') && $request->hasHeader('Authorization', 'Bearer '.SECOND_TOKEN)) {
            $retriedWithNewToken = true;
        }

        return true;
    });

    expect($renewed)->toBe(1)
        ->and($retriedWithNewToken)->toBeTrue();
});

it('reports a second consecutive 401 rather than retrying forever, without blaming the enrollment', function (): void {
    // **The renewal below answers 200, and that is what the message may not contradict.** Core puts
    // `sessions/{id}/renew` inside the `EnsureInstallation` group, which refuses unless
    // `revoked_at === null && expires_at->isFuture()`, and `Session::renew()` returns normally only
    // on a 2xx. So a fake that renews 200 and then refuses the retry is the state where the
    // enrollment has just been PROVED usable -- and until #185 the diagnostic named it as the
    // likely cause, which is what this test pinned.
    Http::fake([
        '*/api/sessions/7/renew' => Http::response(['token' => SECOND_TOKEN, 'expires_in' => 3600], 200),
        '*/api/sessions' => Http::response(['session_id' => 7, 'token' => FIRST_TOKEN, 'expires_in' => 3600], 201),
        '*/api/mcp' => Http::response(['error' => 'Unauthenticated.'], 401),
    ]);

    $out = tmpfile();
    $diagnostics = [];

    new Bridge(startedSession(), BRIDGE_SERVICE)
        ->run(streamOf("{\"jsonrpc\":\"2.0\",\"id\":1}\n"), $out, function (string $m) use (&$diagnostics): void {
            $diagnostics[] = $m;
        });

    rewind($out);

    // Nothing on stdout, because there was no protocol response to write
    expect(trim((string) stream_get_contents($out)))->toBeEmpty()
        ->and($diagnostics)->toHaveCount(1)
        // **The first two are the control for the three after them.** #185 is a rewording, not a
        // removal: without these, deleting the throw outright would satisfy every absence below and
        // the test would report clean on a bridge that had gone silent. Measured both ways.
        ->and($diagnostics[0])->toContain('refused it on the next call')
        ->and($diagnostics[0])->toContain('did not reach the fleet')
        // Not the cause the preceding renewal disproved ...
        ->and($diagnostics[0])->not->toContain('revoked')
        ->and($diagnostics[0])->not->toContain('installation')
        // ... nor an instruction that repairs none of what is actually left.
        ->and($diagnostics[0])->not->toContain('enroll');
});

it('writes only parseable protocol messages to stdout, across a whole run', function (): void {
    Http::fake([
        '*/api/sessions' => Http::response(['session_id' => 7, 'token' => FIRST_TOKEN, 'expires_in' => 3600], 201),
        '*/api/mcp' => Http::sequence()
            ->push('{"jsonrpc":"2.0","id":1,"result":{}}', 200)

            // A notification: `laravel/mcp` answers 202 with NO body. `notifications/initialized`
            // did this on every real session until the bridge began answering it itself (cli#127);
            // a cancellation is still relayed and is answered the same way
            ->push('', 202)

            // An nginx page in front of the fleet -- multi-line, and not JSON at all
            ->push("<html>\n<head><title>502 Bad Gateway</title></head>\n<body>502</body>\n</html>", 502)

            ->push('{"jsonrpc":"2.0","id":4,"result":{}}', 200),
    ]);

    $out = tmpfile();

    $messages = implode("\n", [
        '{"jsonrpc":"2.0","id":1,"method":"tools/list"}',
        '{"jsonrpc":"2.0","method":"notifications/cancelled","params":{"requestId":1}}',
        '{"jsonrpc":"2.0","id":3,"method":"tools/call"}',
        '{"jsonrpc":"2.0","id":4,"method":"tools/list"}',
    ])."\n";

    new Bridge(startedSession(), BRIDGE_SERVICE)
        ->run(streamOf($messages), $out, fn (string $m): null => null);

    rewind($out);

    // **Nothing filtered.** The first version of this test dropped empty lines before asserting,
    // which removed exactly the blank line a 202 notification produced -- so the test written to
    // catch a malformed stdout line could not see the one the bridge emitted on every session.
    $raw = (string) stream_get_contents($out);
    $lines = $raw === '' ? [] : explode("\n", rtrim($raw, "\n"));

    foreach ($lines as $index => $line) {
        expect(json_decode($line, true))->toBeArray("stdout line {$index} must parse: ".var_export($line, true));
    }

    // Two of the four exchanges had a protocol message to relay. The notification's empty 202
    // reached nothing, and the HTML page did not reach stdout: the request it answered got an
    // error saying so instead, since the harness would otherwise wait on id 3 forever (#245).
    expect($lines)->toHaveCount(3)
        ->and(json_decode($lines[1], true))->toBe([
            'jsonrpc' => '2.0',
            'id' => 3,
            'error' => ['code' => -32603, 'message' => 'The service answered with something that is not a protocol message.'],
        ]);
});

it('relays a message that arrives in pieces, rather than forwarding each piece', function (): void {
    Http::fake([
        '*/api/sessions' => Http::response(['session_id' => 7, 'token' => FIRST_TOKEN, 'expires_in' => 3600], 201),
        '*/api/mcp' => Http::response('{"jsonrpc":"2.0","id":1,"result":{"ok":true}}', 200),
    ]);

    // **A real socket pair with a forked writer, because nothing simpler reproduces this.**
    // `stream_select` reports a stream readable when ANY bytes have arrived, and on a non-blocking
    // stream a read hands back whatever is there. Measured before the fix: a 200,065-byte
    // `tools/call` arrived as 24 fragments at the socket buffer's 8,192 bytes, and every fragment
    // was forwarded as its own malformed request -- 0 valid messages out of 24.
    //
    // A `tmpfile()` always returns whole lines however large they are, which is why the suite
    // could not see this at all. The writer has to be a separate process for the pieces to arrive
    // separately rather than all sitting in the buffer before the first read.
    $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

    if (! \is_array($pair)) {
        $this->markTestSkipped('this platform would not give us a socket pair');
    }

    [$readEnd, $writeEnd] = $pair;

    $big = '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"blob":"'.str_repeat('x', 200000).'"}}';

    $child = pcntl_fork();

    if ($child === 0) {
        // The writer. Blocking, so it fills the buffer, waits for the reader to drain, and
        // continues -- which is exactly how a harness sends a large message.
        fclose($readEnd);
        fwrite($writeEnd, $big."\n");
        fclose($writeEnd);
        exit(0);
    }

    fclose($writeEnd);

    $out = tmpfile();

    new Bridge(startedSession(), BRIDGE_SERVICE)->run($readEnd, $out, fn (string $m): null => null);

    pcntl_waitpid($child, $status);

    rewind($out);

    $lines = array_filter(explode("\n", (string) stream_get_contents($out)), fn (string $l): bool => $l !== '');

    // One message in, one request out, one reply back
    expect($lines)->toHaveCount(1);

    $forwarded = [];

    Http::assertSent(function (Request $request) use (&$forwarded): bool {
        if (str_contains($request->url(), '/api/mcp')) {
            $forwarded[] = strlen((string) $request->body());
        }

        return true;
    });

    // The whole message, reassembled -- not 24 fragments
    expect($forwarded)->toBe([strlen($big)]);
})->skip(! \function_exists('pcntl_fork'), 'needs pcntl_fork to write from a separate process');

it('stops when asked, leaving the loop for the caller to clean up after', function (): void {
    Http::fake([
        '*/api/sessions' => Http::response(['session_id' => 7, 'token' => FIRST_TOKEN, 'expires_in' => 3600], 201),
        '*/api/mcp' => Http::response('{"jsonrpc":"2.0","id":1,"result":{}}', 200),
    ]);

    $bridge = new Bridge(startedSession(), BRIDGE_SERVICE);

    // What a SIGTERM handler does
    $bridge->stop();

    $out = tmpfile();

    $bridge->run(streamOf("{\"jsonrpc\":\"2.0\",\"id\":1}\n"), $out, fn (string $m): null => null);

    rewind($out);

    // Stopped before forwarding anything
    expect(trim((string) stream_get_contents($out)))->toBeEmpty();
});

it('heartbeats while it sits idle, so the sweep does not take its work', function (): void {
    Http::fake([
        '*/api/sessions' => Http::response(['session_id' => 7, 'token' => FIRST_TOKEN, 'expires_in' => 3600], 201),
        '*/api/agent/heartbeat' => Http::response('', 200),
        '*/api/mcp' => Http::response('{"jsonrpc":"2.0","id":1,"result":{}}', 200),
    ]);

    $out = tmpfile();

    // Due on the first pass. The default is a minute, read from `time()` inside a loop that blocks
    // on `stream_select`, so nothing outside the loop can move the clock and a test at the default
    // would have to sit through a real one.
    new Bridge(startedSession(), BRIDGE_SERVICE, heartbeatSeconds: 0)
        ->run(streamOf("{\"jsonrpc\":\"2.0\",\"id\":1}\n"), $out, fn (string $m): null => null);

    $heartbeats = 0;

    Http::assertSent(function (Request $request) use (&$heartbeats): bool {
        if (str_ends_with($request->url(), '/agent/heartbeat')) {
            $heartbeats++;

            // The session token, never the installation credential: a heartbeat is an ordinary
            // agent request and goes out on the same credential every other one does
            expect($request->hasHeader('Authorization', 'Bearer '.FIRST_TOKEN))->toBeTrue();
        }

        return true;
    });

    expect($heartbeats)->toBeGreaterThan(0);
});

it('sends no heartbeat before one is due, which is what makes the test above mean something', function (): void {
    // The negative control, kept as a test rather than run once by hand. Without it, the assertion
    // above would pass just as happily against a bridge that heartbeats on every single pass --
    // which would be a bridge hammering the service at the message rate, not a working schedule.
    Http::fake([
        '*/api/sessions' => Http::response(['session_id' => 7, 'token' => FIRST_TOKEN, 'expires_in' => 3600], 201),
        '*/api/agent/heartbeat' => Http::response('', 200),
        '*/api/mcp' => Http::response('{"jsonrpc":"2.0","id":1,"result":{}}', 200),
    ]);

    $out = tmpfile();

    new Bridge(startedSession(), BRIDGE_SERVICE)
        ->run(streamOf("{\"jsonrpc\":\"2.0\",\"id\":1}\n"), $out, fn (string $m): null => null);

    Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/agent/heartbeat'));
});

/**
 * Every line the bridge wrote, each decoded, failing the test on any that is not a protocol message.
 *
 * @param  resource  $out  What the bridge wrote to.
 * @return list<array<array-key, mixed>>
 */
function bridgeReplies($out): array
{
    rewind($out);

    $raw = (string) stream_get_contents($out);
    $replies = [];

    foreach ($raw === '' ? [] : explode("\n", rtrim($raw, "\n")) as $line) {
        $reply = json_decode($line, true);

        if (! is_array($reply)) {
            throw new RuntimeException('stdout carried something that is not a protocol message: '.$line);
        }

        $replies[] = $reply;
    }

    return $replies;
}

it('answers a call refused, renewed and refused again, and keeps relaying', function (): void {
    // #185's state: the renewal succeeds and the fleet still refuses the new token. The bridge
    // carries on, so before #245 the harness waited on this id until its own timeout.
    Http::fake([
        '*/api/sessions/7/renew' => Http::response(['token' => SECOND_TOKEN, 'expires_in' => 3600], 200),
        '*/api/sessions' => Http::response(['session_id' => 7, 'token' => FIRST_TOKEN, 'expires_in' => 3600], 201),
        '*/api/mcp' => Http::sequence()
            ->push('', 401)
            ->push('', 401)
            ->push('{"jsonrpc":"2.0","id":2,"result":{"tools":[]}}', 200),
    ]);

    $out = tmpfile();

    new Bridge(startedSession(), BRIDGE_SERVICE)->run(streamOf(
        '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"task_list"}}'."\n"
            .'{"jsonrpc":"2.0","id":2,"method":"tools/list"}'."\n"
    ), $out, fn (string $m): null => null);

    $replies = bridgeReplies($out);

    expect($replies)->toHaveCount(2)
        ->and($replies[0]['id'])->toBe(1)
        ->and($replies[0]['error'])->toBe([
            'code' => -32603,
            'message' => 'The service issued a new token for this session and refused it on the next call, so this message did not reach the fleet.',
        ])

        // Still relaying: the next call reached the fleet and its answer came back.
        ->and($replies[1])->toBe(['jsonrpc' => '2.0', 'id' => 2, 'result' => ['tools' => []]]);
});

it('answers a call whose request never reached the fleet', function (): void {
    Http::fake([
        '*/api/sessions' => Http::response(['session_id' => 7, 'token' => FIRST_TOKEN, 'expires_in' => 3600], 201),
        '*/api/mcp' => function (): never {
            throw new ConnectionException('cURL error 28: Operation timed out');
        },
    ]);

    $out = tmpfile();

    new Bridge(startedSession(), BRIDGE_SERVICE)->run(streamOf(
        '{"jsonrpc":"2.0","id":"call-1","method":"tools/call","params":{"name":"task_list"}}'."\n"
            // A notification whose forwarding fails the same way gets no reply: it names no request.
            .'{"jsonrpc":"2.0","method":"notifications/cancelled","params":{"requestId":"call-0"}}'."\n"
    ), $out, fn (string $m): null => null);

    expect(bridgeReplies($out))->toBe([[
        'jsonrpc' => '2.0',
        'id' => 'call-1',
        'error' => ['code' => -32603, 'message' => 'cURL error 28: Operation timed out'],
    ]]);
});

it('answers a request the fleet answered with nothing', function (): void {
    // An empty body is right for a notification and wrong for a request, which is owed an answer.
    Http::fake([
        '*/api/sessions' => Http::response(['session_id' => 7, 'token' => FIRST_TOKEN, 'expires_in' => 3600], 201),
        '*/api/mcp' => Http::response('', 200),
    ]);

    $out = tmpfile();

    new Bridge(startedSession(), BRIDGE_SERVICE)->run(streamOf(
        '{"jsonrpc":"2.0","id":5,"method":"tools/call","params":{"name":"task_list"}}'."\n"
            .'{"jsonrpc":"2.0","method":"notifications/cancelled","params":{"requestId":4}}'."\n"
    ), $out, fn (string $m): null => null);

    expect(bridgeReplies($out))->toBe([[
        'jsonrpc' => '2.0',
        'id' => 5,
        'error' => ['code' => -32603, 'message' => 'The service answered this request with nothing.'],
    ]]);
});

it('keeps the first page of tools when a later page fails', function (): void {
    // `everyTool()` returns the first page for every other surprise; a timeout on page two must not
    // cost an agent the tools page one listed, which Cursor, reading only one page, depends on.
    $first = '{"jsonrpc":"2.0","id":1,"result":{"tools":[{"name":"task_list","inputSchema":{"type":"object","properties":{}}}],"nextCursor":"p2"}}';
    $pages = 0;

    Http::fake([
        '*/api/sessions' => Http::response(['session_id' => 7, 'token' => FIRST_TOKEN, 'expires_in' => 3600], 201),
        '*/api/mcp' => function () use (&$pages, $first) {
            if (++$pages === 1) {
                return Http::response($first, 200);
            }

            throw new ConnectionException('cURL error 28: Operation timed out');
        },
    ]);

    $out = tmpfile();

    new Bridge(startedSession(), BRIDGE_SERVICE)
        ->run(streamOf('{"jsonrpc":"2.0","id":1,"method":"tools/list"}'."\n"), $out, fn (string $m): null => null);

    expect(bridgeReplies($out))->toBe([json_decode($first, true)])
        ->and($pages)->toBe(2);
});

it('keeps the id when the reason carries bytes that are not UTF-8', function (): void {
    // Refused outright, the reply would be re-encoded with a null id the harness cannot match.
    Http::fake([
        '*/api/sessions' => Http::response(['session_id' => 7, 'token' => FIRST_TOKEN, 'expires_in' => 3600], 201),
        '*/api/mcp' => function (): never {
            throw new ConnectionException("Could not resolve host: bad\xff\xfehost");
        },
    ]);

    $out = tmpfile();

    new Bridge(startedSession(), BRIDGE_SERVICE)
        ->run(streamOf('{"jsonrpc":"2.0","id":9,"method":"tools/call","params":{"name":"task_list"}}'."\n"), $out, fn (string $m): null => null);

    $replies = bridgeReplies($out);
    $error = $replies[0]['error'] ?? null;

    expect($replies)->toHaveCount(1)
        ->and($replies[0]['id'])->toBe(9)
        ->and(is_array($error) ? $error['message'] ?? null : null)->toStartWith('Could not resolve host: bad');
});

it('carries on relaying when the answer to a failed call cannot be written either', function (): void {
    // The failure may be stdout itself. Writing the error then fails too, and that second failure
    // must not escape the loop, which used to carry on after the first.
    $calls = 0;

    // Counted here rather than through `Http::recorded()`, which records a response and so never
    // sees a request whose fake throws.
    Http::fake([
        '*/api/sessions' => Http::response(['session_id' => 7, 'token' => FIRST_TOKEN, 'expires_in' => 3600], 201),
        '*/api/mcp' => function () use (&$calls): never {
            $calls++;

            throw new ConnectionException('cURL error 7: Failed to connect');
        },
    ]);

    [$out, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP) ?: throw new RuntimeException('No socket pair.');
    fclose($peer);

    new Bridge(startedSession(), BRIDGE_SERVICE)->run(streamOf(
        '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"name":"task_list"}}'."\n"
            .'{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"task_list"}}'."\n"
    ), $out, fn (string $m): null => null);

    // Both calls reached the fleet: the loop survived the first unwritable answer.
    expect($calls)->toBe(2);
})->skipOnWindows();

/**
 * Run one joined bridge three times, against a service answering the watcher heartbeat as given.
 *
 * **Three runs of one bridge rather than one run of three lines**, because a run reads its whole
 * input in one chunk and so makes one periodic pass: a single run could not tell a heartbeat sent
 * once from one sent every pass. The bridge's own state carries across the runs.
 *
 * @param  callable(): mixed  $watcher  What `agent/watcher` answers, called per request.
 * @return list<string> What the bridge said on stderr.
 */
function watchingRuns(callable $watcher, int $heartbeatSeconds): array
{
    Http::fake([
        '*/api/sessions' => Http::response(['session_id' => 7, 'token' => FIRST_TOKEN, 'expires_in' => 3600, 'feed_cursor' => 1], 201),
        '*/api/agent/heartbeat' => Http::response('', 200),
        '*/api/agent/watcher' => $watcher,
        '*/api/events*' => Http::response(['events' => [], 'cursor' => 1], 200),
        '*/api/mcp' => Http::response('{"jsonrpc":"2.0","id":1,"result":{"tools":[]}}', 200),
    ]);

    $session = startedSession();
    $said = [];
    $state = sys_get_temp_dir().'/rc-watcher-'.bin2hex(random_bytes(6));
    putenv('XDG_STATE_HOME='.$state);

    $bridge = new Bridge($session, BRIDGE_SERVICE, heartbeatSeconds: $heartbeatSeconds, follower: new FleetFollower($session, BRIDGE_SERVICE, new PendingEvents(BRIDGE_SERVICE, 'claude', 'watch'), 0));

    for ($run = 0; $run < 3; $run++) {
        $bridge->run(streamOf("{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/list\"}\n"), tmpfile(), function (string $m) use (&$said): void {
            $said[] = $m;
        });
    }

    array_map(unlink(...), glob($state.'/robot-council/pending/*') ?: []);
    @rmdir($state.'/robot-council/pending');
    @rmdir($state.'/robot-council');
    @rmdir($state);
    putenv('XDG_STATE_HOME');

    return $said;
}

/**
 * The watcher heartbeats that reached the service.
 */
function watcherBeats(): int
{
    return Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/api/agent/watcher'))->count();
}

/**
 * What the bridge said about the watcher heartbeat.
 *
 * @param  list<string>  $said  Everything it said.
 * @return list<string>
 */
function watcherLines(array $said): array
{
    return array_values(array_filter($said, fn (string $m): bool => str_contains($m, 'watcher heartbeat')));
}

it('sends the watcher heartbeat on every due pass while the follower watches, on the session token', function (): void {
    watchingRuns(fn () => Http::response('', 204), heartbeatSeconds: 0);

    expect(watcherBeats())->toBe(3);

    Http::assertSent(fn (Request $request): bool => ! str_ends_with($request->url(), '/api/agent/watcher')
        || $request->hasHeader('Authorization', 'Bearer '.FIRST_TOKEN));
});

it('sends it once per interval, not once per pass', function (): void {
    // The negative control for the test above: at the default interval three passes inside one
    // minute send the first heartbeat and no more.
    watchingRuns(fn () => Http::response('', 204), heartbeatSeconds: Bridge::HEARTBEAT_SECONDS);

    expect(watcherBeats())->toBe(1);
});

it('keeps the interval inside the staleness core allows a watcher', function (): void {
    // Core reads a watcher as `stale` past `presence.watcher_stale_after_seconds`, 90 by default
    // (robot-council/core#350); the watcher heartbeat is sent on the presence heartbeat's interval.
    expect(Bridge::HEARTBEAT_SECONDS)->toBeLessThan(90);
});

it('sends no watcher heartbeat from a bridge that has not joined, whatever the agent calls', function (): void {
    Http::fake([
        '*/api/agent/watcher' => Http::response('', 204),
    ]);

    new Bridge(null, BRIDGE_SERVICE, heartbeatSeconds: 0)
        ->run(streamOf("{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/list\"}\n"), tmpfile(), fn (string $m): null => null);

    expect(watcherBeats())->toBe(0);
});

it('stops sending on a service without the route, and says so once', function (): void {
    $told = watcherLines(watchingRuns(fn () => Http::response('', 404), heartbeatSeconds: 0));

    expect(watcherBeats())->toBe(1)
        ->and($told)->toHaveCount(1)
        ->and($told[0])->toContain('robot-council/core#337');
});

it('keeps sending through failed watcher heartbeats, saying so once for a run of them', function (): void {
    $attempts = 0;

    // Counted here, since `Http::recorded()` never sees a request whose fake throws.
    $told = watcherLines(watchingRuns(function () use (&$attempts): never {
        $attempts++;

        throw new ConnectionException('cURL error 28: Operation timed out');
    }, heartbeatSeconds: 0));

    expect($attempts)->toBe(3)
        ->and(Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/api/mcp')))->toHaveCount(3)
        ->and($told)->toHaveCount(1)
        ->and($told[0])->toContain('could not be sent');
});

it('says a refused watcher heartbeat with its status, and says it again after one lands', function (): void {
    // Failed, landed, failed: two runs of failure, so two lines -- a heartbeat that landed ends the
    // first run, and silence about the second would read as a watcher that recovered for good.
    $answers = [500, 204, 503];

    $told = watcherLines(watchingRuns(function () use (&$answers) {
        return Http::response('', array_shift($answers) ?? 204);
    }, heartbeatSeconds: 0));

    expect(watcherBeats())->toBe(3)
        ->and($told)->toBe([
            'The watcher heartbeat was refused (HTTP 500); the lane board will read this watcher as stale until one lands.',
            'The watcher heartbeat was refused (HTTP 503); the lane board will read this watcher as stale until one lands.',
        ]);
});

it('sends no watcher heartbeat from a joined bridge with nothing following the feed', function (): void {
    // The follower is what watches. A session without one has nothing to report as watching.
    Http::fake([
        '*/api/sessions' => Http::response(['session_id' => 7, 'token' => FIRST_TOKEN, 'expires_in' => 3600], 201),
        '*/api/agent/heartbeat' => Http::response('', 200),
        '*/api/agent/watcher' => Http::response('', 204),
        '*/api/mcp' => Http::response('{"jsonrpc":"2.0","id":1,"result":{}}', 200),
    ]);

    new Bridge(startedSession(), BRIDGE_SERVICE, heartbeatSeconds: 0)
        ->run(streamOf("{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/list\"}\n"), tmpfile(), fn (string $m): null => null);

    expect(watcherBeats())->toBe(0);
});

it('asks for a renewal when the watcher heartbeat is refused, whether or not the presence heartbeat was due', function (): void {
    // The two are scheduled apart, so a refused watcher beat can land in a pass where the presence
    // heartbeat was not sent. Its 401 means the same thing, and asks for the same renewal.
    Http::fake([
        '*/api/sessions/7/renew' => Http::response(['token' => SECOND_TOKEN, 'expires_in' => 3600], 200),
        '*/api/sessions' => Http::response(['session_id' => 7, 'token' => FIRST_TOKEN, 'expires_in' => 3600, 'feed_cursor' => 1], 201),
        '*/api/agent/watcher' => Http::response(null, 401),
        '*/api/events*' => Http::response(['events' => [], 'cursor' => 1], 200),
        '*/api/mcp' => Http::response('{"jsonrpc":"2.0","id":1,"result":{}}', 200),
    ]);

    $session = startedSession();
    $state = sys_get_temp_dir().'/rc-watcher-'.bin2hex(random_bytes(6));
    putenv('XDG_STATE_HOME='.$state);
    $said = [];

    // The presence heartbeat is a minute away, so only the watcher is sent on this pass.
    new Bridge($session, BRIDGE_SERVICE, follower: new FleetFollower($session, BRIDGE_SERVICE, new PendingEvents(BRIDGE_SERVICE, 'claude', 'watch'), 0))
        ->run(streamOf("{\"jsonrpc\":\"2.0\",\"id\":1,\"method\":\"tools/list\"}\n"), tmpfile(), function (string $m) use (&$said): void {
            $said[] = $m;
        });

    array_map(unlink(...), glob($state.'/robot-council/pending/*') ?: []);
    @rmdir($state.'/robot-council/pending');
    @rmdir($state.'/robot-council');
    @rmdir($state);
    putenv('XDG_STATE_HOME');

    expect(Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/agent/heartbeat')))->toBeEmpty()
        ->and(Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/renew')))->toHaveCount(1)
        ->and(watcherLines($said))->toBeEmpty();
});
