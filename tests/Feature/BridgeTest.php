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
use App\Support\Session;
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

it('reports a second consecutive 401 rather than retrying forever', function (): void {
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
        ->and($diagnostics[0])->toContain('revoked');
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

    // Two of the four exchanges had a protocol message to relay; the notification and the HTML
    // page did not, and neither reached stdout
    expect($lines)->toHaveCount(2);
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
