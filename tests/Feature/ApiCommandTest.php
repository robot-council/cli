<?php

declare(strict_types=1);

/**
 * One API call inside a session the command starts and ends.
 *
 * **The assertion that matters is that the session ends when the call FAILS.** A session the
 * service still believes is alive holds whatever it claimed: `core` releases a session's tasks and
 * locks when it ends, and waits for the presence sweep otherwise. A command that starts a session
 * and exits without ending it leaves the fleet holding work nobody is doing.
 *
 * @command  vendor/bin/pest --compact tests/Feature/ApiCommandTest.php
 */

use App\Support\Credentials\Credential;
use App\Support\Credentials\Credentials;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\RecordingStore;
use Tests\Fixtures\TwoStreamOutput;

// Prefixed, because Pest test files share one global namespace: a second `const SERVICE` is a
// PHP warning, and `failOnWarning` turns that into a red run with nothing in the summary.
const API_SERVICE = 'https://fleet.example.test';
const INSTALLATION = 'rcouncil_1|INSTALLATION-CREDENTIAL-0123456789';
const SESSION_TOKEN = 'rcouncil_2|SESSION-TOKEN-abcdef0123456789';

/**
 * A store already holding an enrollment for the service under test.
 */
function enrolled(): RecordingStore
{
    $store = new RecordingStore;

    $store->put(API_SERVICE.'|claude', new Credential(INSTALLATION));

    app()->instance(Credentials::class, new Credentials([$store]));

    return $store;
}

beforeEach(function (): void {
    // **Named rather than detected, in every test in this file.** `laravel/agent-detector` reads
    // the environment, and this suite runs inside a harness on a developer's machine and inside
    // none on CI -- measured: `claude` here, `NULL` with the harness variables cleared. A test
    // left to ambient detection therefore passes on one side and fails on the other, and which
    // side depends on who runs it.
    putenv('ROBOT_COUNCIL_HARNESS=claude');
});

afterEach(function (): void {
    putenv('ROBOT_COUNCIL_HARNESS');
});

/**
 * The service, with the call itself answering whatever a test needs.
 */
function fakeSession(int $callStatus = 200, string $callBody = '{"tasks":[]}'): void
{
    Http::fake([
        '*/api/sessions/*' => Http::response('', 204),
        '*/api/sessions' => Http::response([
            'session_id' => 42,
            'token' => SESSION_TOKEN,
            'abilities' => ['tasks:create'],
            'expires_in' => 3600,
            'feed_cursor' => 7,
        ], 201),
        '*' => Http::response($callBody, $callStatus),
    ]);
}

/**
 * How many times the session-end endpoint was called.
 */
function endCalls(): int
{
    $ended = 0;

    Http::assertSent(function (Request $request) use (&$ended): bool {
        if ($request->method() === 'DELETE' && str_contains($request->url(), '/api/sessions/42')) {
            $ended++;
        }

        return true;
    });

    return $ended;
}

beforeEach(function (): void {
    Http::preventStrayRequests();
});

it('prints the response body and nothing else', function (): void {
    enrolled();
    fakeSession(callBody: '{"tasks":[{"id":1}],"cursor":3}');

    $output = new TwoStreamOutput;

    expect(Artisan::call('api', ['method' => 'GET', 'path' => '/api/tasks', '--service' => API_SERVICE], $output))->toBe(0);

    // Exactly the body and its newline. A harness may pipe this straight into an agent, so a stray
    // line would be parsed as part of the payload -- and `trim()` over a merged buffer would have
    // hidden one that arrived on either side of it (#205).
    expect($output->stdout())->toBe('{"tasks":[{"id":1}],"cursor":3}'."\n")
        ->and($output->stderr())->toBeEmpty();
});

it('ends the session when the service answers an error', function (): void {
    enrolled();
    fakeSession(callStatus: 500, callBody: '{"message":"boom"}');

    expect(Artisan::call('api', ['method' => 'GET', 'path' => '/api/tasks', '--service' => API_SERVICE], new TwoStreamOutput))->toBe(1)
        ->and(endCalls())->toBe(1);
});

it('ends the session when the call throws, which is what the finally is for', function (): void {
    enrolled();

    // A non-2xx does NOT exercise the `finally`: the command returns a failure code rather than
    // throwing, so the try block completes and an `end()` placed after it would still run.
    // Measured -- moving `end()` out of the `finally` left every other test in this file green.
    // A connection dropping mid-call is the case that actually needs it.
    Http::fake([
        '*/api/sessions/*' => Http::response('', 204),
        '*/api/sessions' => Http::response([
            'session_id' => 42, 'token' => SESSION_TOKEN, 'expires_in' => 3600,
        ], 201),
        '*/api/tasks*' => fn () => throw new ConnectionException('Connection reset by peer'),
    ]);

    expect(Artisan::call('api', ['method' => 'GET', 'path' => '/api/tasks', '--service' => API_SERVICE], new TwoStreamOutput))->toBe(1);

    // The session was started, so leaving it open would hold its tasks and locks until the sweep
    expect(endCalls())->toBe(1);
});

it('ends the session exactly once on the happy path too', function (): void {
    enrolled();
    fakeSession();

    Artisan::call('api', ['method' => 'GET', 'path' => '/api/tasks', '--service' => API_SERVICE]);

    expect(endCalls())->toBe(1);
});

it('starts exactly one session per invocation', function (): void {
    enrolled();
    fakeSession();

    Artisan::call('api', ['method' => 'GET', 'path' => '/api/tasks', '--service' => API_SERVICE]);

    $started = 0;

    Http::assertSent(function (Request $request) use (&$started): bool {
        if ($request->method() === 'POST' && str_ends_with($request->url(), '/api/sessions')) {
            $started++;
        }

        return true;
    });

    expect($started)->toBe(1);
});

it('exits non-zero on a non-2xx, with the status off stdout', function (): void {
    // **This test was named for the property and could not see it.** It read `Artisan::output()`,
    // one buffer holding both streams, and asserted only that the body was somewhere in it -- which
    // is true whether the status line sits beside the body on stdout or on stderr where it belongs.
    // It passed for the whole time the status was corrupting the body a caller parses (#205).
    enrolled();
    fakeSession(callStatus: 422, callBody: '{"errors":{"title":["required"]}}');

    $output = new TwoStreamOutput;

    $code = Artisan::call('api', ['method' => 'POST', 'path' => '/api/tasks', '--service' => API_SERVICE], $output);

    // Byte for byte: the body and its newline, and nothing after it.
    expect($code)->toBe(1)
        ->and($output->stdout())->toBe('{"errors":{"title":["required"]}}'."\n")
        ->and($output->stderr())->toBe('robot-council: The service answered HTTP 422.'."\n");
});

it('never puts either credential in its output', function (): void {
    enrolled();
    fakeSession(callStatus: 500, callBody: '{"message":"boom"}');

    $streams = new TwoStreamOutput;

    Artisan::call('api', ['method' => 'GET', 'path' => '/api/tasks', '--service' => API_SERVICE], $streams);

    // **Both streams, since #205.** Diagnostics moved to stderr, and they are the output most
    // likely to quote a credential -- so a check reading stdout alone would have kept passing while
    // covering less and less of what it was written for.
    $output = $streams->stdout().$streams->stderr();

    // Searched for, not argued from the source. The control is that the call demonstrably happened,
    // below -- so this cannot pass by doing nothing.
    expect($output)->not->toContain(INSTALLATION)
        ->not->toContain(SESSION_TOKEN);

    $carried = false;

    Http::assertSent(function (Request $request) use (&$carried): bool {
        if (str_contains($request->url(), '/api/tasks')) {
            $carried = $request->hasHeader('Authorization', 'Bearer '.SESSION_TOKEN);
        }

        return true;
    });

    // The call carried the SESSION token, not the installation credential -- the two-key shape #15
    // decided, exercised rather than assumed
    expect($carried)->toBeTrue();
});

it('refuses when this machine is not enrolled against that service', function (): void {
    $store = new RecordingStore;

    app()->instance(Credentials::class, new Credentials([$store]));

    $output = new TwoStreamOutput;

    $code = Artisan::call('api', ['method' => 'GET', 'path' => '/api/tasks', '--service' => API_SERVICE], $output);

    // On stderr, and stdout empty. Read through `Artisan::output()` this passed whichever stream the
    // refusal went to, which is the blindness #205 was about.
    expect($code)->toBe(1)
        ->and($output->stderr())->toContain('not enrolled')
        ->and($output->stdout())->toBeEmpty();
});

it('reports a revoked credential rather than a bare failure', function (): void {
    enrolled();

    Http::fake(['*/api/sessions' => Http::response(['message' => 'Unauthenticated.'], 401)]);

    $output = new TwoStreamOutput;

    $code = Artisan::call('api', ['method' => 'GET', 'path' => '/api/tasks', '--service' => API_SERVICE], $output);

    // The credential must be absent from BOTH streams. Moving diagnostics to stderr moves the text
    // most likely to quote one, so a check that still read only stdout would have stopped covering
    // the case it exists for.
    expect($code)->toBe(1)
        ->and($output->stderr())->toContain('revoked')
        ->and($output->stdout())->toBeEmpty()
        ->and($output->stdout().$output->stderr())->not->toContain(INSTALLATION);
});

it('refuses a --body that is not JSON', function (): void {
    enrolled();
    fakeSession();

    expect(Artisan::call('api', [
        'method' => 'POST', 'path' => '/api/tasks', '--service' => API_SERVICE, '--body' => 'not json',
    ], new TwoStreamOutput))->toBe(1);

    // And it refused before starting a session, so nothing was left open
    Http::assertNothingSent();
});

it('sends the body it was given', function (): void {
    enrolled();
    fakeSession(callStatus: 201, callBody: '{"id":9}');

    Artisan::call('api', [
        'method' => 'POST', 'path' => '/api/tasks', '--service' => API_SERVICE,
        '--body' => '{"title":"Do the thing","priority":5}',
    ]);

    Http::assertSent(function (Request $request): bool {
        if (str_contains($request->url(), '/api/tasks')) {
            /** @var array<string, mixed> $data */
            $data = $request->data();

            expect($data['title'])->toBe('Do the thing')
                ->and($data['priority'])->toBe(5);
        }

        return true;
    });
});

/**
 * Every failure path that answers before there is a response body to print.
 *
 * Each one is a `return self::FAILURE` reached before `perform()`, so stdout has nothing legitimate
 * to carry and the whole of it is the assertion. The `$arrange` closure puts the command into the
 * state, and `$expect` is the fragment of the message that identifies which path ran -- without it a
 * test could pass on the wrong failure.
 */
dataset('paths that print no body', [
    'no service configured' => [
        function (): array {
            putenv('ROBOT_COUNCIL_SERVICE');
            enrolled();

            return ['method' => 'GET', 'path' => '/api/tasks'];
        },
        'Pass --service',
    ],
    'no credential for this harness' => [
        // Deliberately not enrolled, so `InstallationChoice` has nothing to choose.
        fn (): array => ['method' => 'GET', 'path' => '/api/tasks', '--service' => API_SERVICE],
        'This machine is not enrolled as `claude` against that fleet.',
    ],
    'a body that is not JSON' => [
        function (): array {
            enrolled();

            return ['method' => 'POST', 'path' => '/api/tasks', '--service' => API_SERVICE, '--body' => 'not json'];
        },
        '--body must be valid JSON.',
    ],
    'the session cannot be started' => [
        function (): array {
            enrolled();
            Http::fake(['*' => Http::response('{"message":"nope"}', 500)]);

            return ['method' => 'GET', 'path' => '/api/tasks', '--service' => API_SERVICE];
        },
        'The service refused to start a session (HTTP 500).',
    ],
    'the call does not complete' => [
        function (): array {
            enrolled();
            Http::fake([
                '*/api/sessions/*' => Http::response('', 204),
                '*/api/sessions' => Http::response([
                    'session_id' => 42, 'token' => SESSION_TOKEN,
                    'abilities' => [], 'expires_in' => 3600, 'feed_cursor' => 7,
                ], 201),
                // Keyed on the CALL's path, not `*`. A `*` stub also answered the session start,
                // so the run failed one step earlier and this row was asserting the wrong path.
                '*/api/tasks' => fn (): never => throw new ConnectionException('the fleet is unreachable'),
            ]);

            return ['method' => 'GET', 'path' => '/api/tasks', '--service' => API_SERVICE];
        },
        'The call did not complete.',
    ],
]);

it('writes nothing at all to stdout on a path with no response body', function (callable $arrange, string $identifies): void {
    /** @var array<string, string> $parameters */
    $parameters = $arrange();

    $output = new TwoStreamOutput;

    $code = Artisan::call('api', $parameters, $output);

    // **Byte for byte, and empty is the whole point.** A harness pipes this into an agent, so
    // anything here at all is handed over as though it were an answer.
    //
    // `toBeEmpty()` rather than `toBe('')` because Pest's coding-style set rewrites the latter, and
    // the two differ only on the string `'0'`, which this cannot produce: every write to stdout here
    // goes through `writeln()`, which appends a newline, so the shortest non-empty stdout is two
    // bytes.
    expect($output->stdout())->toBeEmpty()
        // The control for the line above: without it, a command that failed to run at all would
        // also write nothing, and this test would report clean on a broken binary.
        ->and($output->stderr())->toContain($identifies)
        ->and($output->stderr())->toStartWith('robot-council: ')
        ->and($code)->toBe(1);
})->with('paths that print no body');

it('writes the response body and only the response body on a successful call', function (): void {
    enrolled();
    fakeSession(callStatus: 200, callBody: '{"tasks":[]}');

    $output = new TwoStreamOutput;

    $code = Artisan::call('api', ['method' => 'GET', 'path' => '/api/tasks', '--service' => API_SERVICE], $output);

    expect($code)->toBe(0)
        ->and($output->stdout())->toBe('{"tasks":[]}'."\n")
        ->and($output->stderr())->toBeEmpty();
});
