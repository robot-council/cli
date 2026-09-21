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

    expect(Artisan::call('api', ['method' => 'GET', 'path' => '/api/tasks', '--service' => API_SERVICE]))->toBe(0);

    // Exactly the body. A harness may pipe this straight into an agent, so a stray line would be
    // parsed as part of the payload.
    expect(trim(Artisan::output()))->toBe('{"tasks":[{"id":1}],"cursor":3}');
});

it('ends the session when the service answers an error', function (): void {
    enrolled();
    fakeSession(callStatus: 500, callBody: '{"message":"boom"}');

    expect(Artisan::call('api', ['method' => 'GET', 'path' => '/api/tasks', '--service' => API_SERVICE]))->toBe(1)
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

    expect(Artisan::call('api', ['method' => 'GET', 'path' => '/api/tasks', '--service' => API_SERVICE]))->toBe(1);

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
    enrolled();
    fakeSession(callStatus: 422, callBody: '{"errors":{"title":["required"]}}');

    $code = Artisan::call('api', ['method' => 'POST', 'path' => '/api/tasks', '--service' => API_SERVICE]);
    $output = Artisan::output();

    expect($code)->toBe(1)
        ->and($output)->toContain('{"errors":{"title":["required"]}}');
});

it('never puts either credential in its output', function (): void {
    enrolled();
    fakeSession(callStatus: 500, callBody: '{"message":"boom"}');

    Artisan::call('api', ['method' => 'GET', 'path' => '/api/tasks', '--service' => API_SERVICE]);

    $output = Artisan::output();

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

    $code = Artisan::call('api', ['method' => 'GET', 'path' => '/api/tasks', '--service' => API_SERVICE]);

    expect($code)->toBe(1)
        ->and(Artisan::output())->toContain('not enrolled');
});

it('reports a revoked credential rather than a bare failure', function (): void {
    enrolled();

    Http::fake(['*/api/sessions' => Http::response(['message' => 'Unauthenticated.'], 401)]);

    $code = Artisan::call('api', ['method' => 'GET', 'path' => '/api/tasks', '--service' => API_SERVICE]);
    $output = Artisan::output();

    expect($code)->toBe(1)
        ->and($output)->toContain('revoked')
        ->and($output)->not->toContain(INSTALLATION);
});

it('refuses a --body that is not JSON', function (): void {
    enrolled();
    fakeSession();

    expect(Artisan::call('api', [
        'method' => 'POST', 'path' => '/api/tasks', '--service' => API_SERVICE, '--body' => 'not json',
    ]))->toBe(1);

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
