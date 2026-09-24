<?php

declare(strict_types=1);

/**
 * What joining does on this machine: which value wins, what a role request leaves behind, and that
 * a join reporting failure leaves no session on the fleet (cli#127).
 *
 * @command  vendor/bin/pest --compact tests/Feature/FleetJoinTest.php
 */
use App\Support\Credentials\Credential;
use App\Support\Credentials\Credentials;
use App\Support\FleetJoin;
use App\Support\Joined;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\RecordingStore;

const FLEET_JOIN_SERVICE = 'https://fleet.example.test';

/**
 * The fake service, with the role endpoint and the fleet question answered as given.
 *
 * @param  mixed  $role  The response for `POST agent/role`.
 */
function fleetJoinService(mixed $role = null, bool $fleetCanDirect = true): void
{
    Http::fake([
        '*/api/sessions/*' => Http::response('', 204),
        '*/api/sessions' => Http::response(['session_id' => 61, 'token' => 'rcouncil_2|T', 'expires_in' => 3600, 'feed_cursor' => 1, 'abilities' => []], 201),
        '*/api/agent/session' => Http::response(['fleet_can_direct' => $fleetCanDirect], 200),
        '*/api/agent/role' => $role ?? Http::response(['session_id' => 61, 'pending' => true, 'requested_role' => 'coordinator', 'role' => 'build'], 202),
    ]);
}

/**
 * A checkout whose `origin` names the given repository, in a directory this test owns.
 */
function fleetJoinCheckout(string $origin): string
{
    $directory = sys_get_temp_dir().'/fleet-join-'.bin2hex(random_bytes(6));

    File::ensureDirectoryExists($directory);
    exec(sprintf('git -C %s init -q && git -C %s remote add origin %s', escapeshellarg($directory), escapeshellarg($directory), escapeshellarg($origin)));

    return $directory;
}

/**
 * A join with the given flags, enrolled as `claude` unless told otherwise.
 *
 * @param  list<string>  $said  What it said to the operator, appended to.
 * @param  callable(string):void|null  $diagnostic  What to call instead of recording.
 */
function fleetJoin(array &$said = [], ?string $repository = null, ?string $workLocation = null, ?string $directory = null, bool $enrolled = true, ?callable $diagnostic = null): FleetJoin
{
    $store = new RecordingStore;

    if ($enrolled) {
        $store->put(FLEET_JOIN_SERVICE.'|claude', new Credential('rcouncil_1|FLEET-JOIN-INSTALLATION'));
    }

    return new FleetJoin(
        app(Factory::class),
        new Credentials([$store]),
        FLEET_JOIN_SERVICE,
        'claude',
        'claude',
        null,
        $repository,
        $workLocation,
        $diagnostic ?? function (string $message) use (&$said): void {
            $said[] = $message;
        },
        $directory,
    );
}

/**
 * Every session start's body, in the order they were sent.
 *
 * **All of them, because `Http::recorded()` accumulates across a test** rather than per fake, so a
 * test that joins three times reads three bodies here and picks by position.
 *
 * @return list<array<array-key, mixed>>
 */
function fleetJoinStartBodies(): array
{
    return array_values(Http::recorded(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/api/sessions'))
        ->map(fn (array $pair): array => (array) json_decode($pair[0]->body(), true))
        ->all());
}

/**
 * How many times a session was ended.
 */
function fleetJoinEnds(): int
{
    return Http::recorded(fn (Request $request): bool => $request->method() === 'DELETE')->count();
}

beforeEach(function (): void {
    Http::preventStrayRequests();

    putenv('XDG_STATE_HOME='.sys_get_temp_dir().'/fleet-join-state');
});

afterEach(function (): void {
    putenv('XDG_STATE_HOME');

    File::deleteDirectory(sys_get_temp_dir().'/fleet-join-state');
});

it('takes what join was told over the flags, and the flags over the checkout', function (): void {
    $checkout = fleetJoinCheckout('https://github.com/octo/from-checkout.git');

    try {
        fleetJoinService();
        fleetJoin(repository: 'octo/from-flag', directory: $checkout)(['role' => null, 'repository' => 'octo/from-join', 'work_location' => 'from-join']);
        fleetJoin(repository: 'octo/from-flag', directory: $checkout)(['role' => null, 'repository' => null, 'work_location' => null]);
        fleetJoin(directory: $checkout)(['role' => null, 'repository' => null, 'work_location' => null]);
    } finally {
        File::deleteDirectory($checkout);
    }

    expect(fleetJoinStartBodies())->toHaveCount(3);

    [$told, $flagged, $read] = fleetJoinStartBodies();

    expect([$told['repository'] ?? null, $told['work_location'] ?? null])->toBe(['octo/from-join', 'from-join'])
        ->and($flagged['repository'] ?? null)->toBe('octo/from-flag')
        ->and($read['repository'] ?? null)->toBe('octo/from-checkout');
});

it('names what is missing when this machine has no credential, and starts nothing', function (): void {
    fleetJoinService();

    expect(fn (): Joined => fleetJoin(enrolled: false)(['role' => null, 'repository' => null, 'work_location' => null]))
        ->toThrow(RuntimeException::class, 'not enrolled')
        ->and(Http::recorded())->toBeEmpty();
});

it('asks for a role other than build, and says an administrator decides', function (): void {
    fleetJoinService();

    $joined = fleetJoin()(['role' => 'coordinator', 'repository' => null, 'work_location' => null]);

    expect($joined->summary)->toContain('asked for the coordinator role; an administrator decides')
        ->and(Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/api/agent/role') && $request['role'] === 'coordinator')->count())->toBe(1);
});

it('keeps the session as build when the role request cannot reach the fleet', function (): void {
    fleetJoinService(role: Http::failedConnection());

    $joined = fleetJoin()(['role' => 'coordinator', 'repository' => null, 'work_location' => null]);

    // **Joined, and saying so.** A dropped connection between the two calls must not end a
    // session that holds less than was asked for and never more
    expect($joined->session->id())->toBe(61)
        ->and($joined->summary)->toContain('It joined as build: asking for coordinator failed.')
        ->and(fleetJoinEnds())->toBe(0);
});

it('says the role is held already when the fleet has nothing to decide', function (): void {
    fleetJoinService(role: Http::response(['session_id' => 61, 'pending' => false, 'requested_role' => null, 'role' => 'coordinator'], 200));

    expect(fleetJoin()(['role' => 'coordinator', 'repository' => null, 'work_location' => null])->summary)
        ->toContain('It holds the coordinator role already.');
});

it('says no request was recorded when the fleet answers with a different role', function (): void {
    fleetJoinService(role: Http::response(['session_id' => 61, 'pending' => false, 'requested_role' => null, 'role' => 'build'], 200));

    expect(fleetJoin()(['role' => 'coordinator', 'repository' => null, 'work_location' => null])->summary)
        ->toContain('the fleet recorded no request; it holds build');
});

it('ends the session it started when something after the start fails', function (): void {
    // The fleet-cannot-deliver line is said after the start; a diagnostic that fails there is the
    // failure after the start this guards
    fleetJoinService(fleetCanDirect: false);

    $failing = function (string $message): void {
        if (str_contains($message, 'coordinator')) {
            throw new RuntimeException('stderr is gone');
        }
    };

    $join = fleetJoin(diagnostic: $failing);

    expect(fn (): Joined => $join(['role' => null, 'repository' => null, 'work_location' => null]))
        ->toThrow(RuntimeException::class, 'was ended')
        ->and(fleetJoinEnds())->toBe(1)
        ->and($join->pending())->toBeNull();
});

it('opens the sink only for a join that succeeded', function (): void {
    fleetJoinService();

    $join = fleetJoin();

    expect($join->pending())->toBeNull();

    $join(['role' => null, 'repository' => null, 'work_location' => null]);

    expect($join->pending())->not->toBeNull();
});
