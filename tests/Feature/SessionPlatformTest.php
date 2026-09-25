<?php

declare(strict_types=1);

/**
 * The operating system family and architecture a session reports when it starts (#266).
 *
 * @command  vendor/bin/pest --compact tests/Feature/SessionPlatformTest.php
 */

use App\Support\Credentials\Credential;
use App\Support\Platform;
use App\Support\Session;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const PLATFORM_SERVICE = 'https://fleet.example.test';

beforeEach(function (): void {
    Http::preventStrayRequests();
});

/**
 * The bodies of every session start the fake service received, decoded.
 *
 * @return list<array<array-key, mixed>>
 */
function sessionStarts(): array
{
    return array_values(Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/api/sessions'))
        ->map(fn (array $pair): array => $pair[0]->data())
        ->all());
}

/**
 * A session started against the fake service.
 */
function platformSession(): Session
{
    $session = new Session(app(Factory::class), PLATFORM_SERVICE, new Credential('rcouncil_1|PLATFORM'));
    $session->start(null, 'robot-council/cli', 'primary');

    return $session;
}

it('reports the OS family PHP runs on, and a short lower-case architecture', function (): void {
    Http::fake(['*/api/sessions' => Http::response(['session_id' => 5, 'token' => 'rcouncil_2|T', 'expires_in' => 3600], 201)]);

    platformSession();

    $platform = sessionStarts()[0]['platform'] ?? null;

    if (! is_array($platform)) {
        throw new RuntimeException('The session start carried no platform.');
    }

    // Read from this machine, whichever it is; the three CI platforms each read their own.
    expect($platform['os_family'])->toBe(PHP_OS_FAMILY)
        ->and($platform['os_family'])->toBeIn(Platform::OS_FAMILIES)
        ->and($platform['arch'])->toBe(strtolower(php_uname('m')))
        ->and($platform['arch'])->toMatch(Platform::ARCH);
});

it('sends the platform beside the work identity, which is unchanged', function (): void {
    Http::fake(['*/api/sessions' => Http::response(['session_id' => 5, 'token' => 'rcouncil_2|T', 'expires_in' => 3600], 201)]);

    platformSession();

    expect(array_keys(sessionStarts()[0]))->toBe(['repository', 'work_location', 'platform'])
        ->and(sessionStarts()[0]['repository'])->toBe('robot-council/cli');
});

it('starts against a service that ignores the field, which is every core before #351', function (): void {
    Http::fake(['*/api/sessions' => Http::response(['session_id' => 5, 'token' => 'rcouncil_2|T', 'expires_in' => 3600], 201)]);

    expect(platformSession()->id())->toBe(5)
        ->and(sessionStarts())->toHaveCount(1);
});

it('starts without the platform when the service refuses it, rather than failing', function (): void {
    Http::fake(['*/api/sessions' => Http::sequence()
        ->push(['message' => 'The platform.os_family field is invalid.', 'errors' => ['platform.os_family' => ['The selected platform.os_family is invalid.']]], 422)
        ->push(['session_id' => 6, 'token' => 'rcouncil_2|T', 'expires_in' => 3600], 201)]);

    $session = platformSession();
    $starts = sessionStarts();

    expect($session->id())->toBe(6)
        ->and($starts)->toHaveCount(2)
        ->and($starts[0])->toHaveKey('platform')
        ->and($starts[1])->not->toHaveKey('platform')
        ->and($starts[1]['repository'])->toBe('robot-council/cli');
});

it('still refuses a 422 about something else, and sends it once', function (): void {
    Http::fake(['*/api/sessions' => Http::response(['message' => 'The work location field format is invalid.', 'errors' => ['work_location' => ['The work location field format is invalid.']]], 422)]);

    expect(fn (): Session => platformSession())->toThrow(RuntimeException::class, 'HTTP 422')
        ->and(sessionStarts())->toHaveCount(1);
});

it('maps an OS family core does not know to Unknown, and leaves out an architecture it would refuse', function (): void {
    expect(Platform::osFamily('Darwin'))->toBe('Darwin')
        ->and(Platform::osFamily('Haiku'))->toBe('Unknown')
        ->and(Platform::arch('AMD64'))->toBe('amd64')
        ->and(Platform::arch(' arm64 '))->toBe('arm64')
        ->and(Platform::arch('x86_64'))->toBe('x86_64')
        ->and(Platform::arch(''))->toBeNull()
        ->and(Platform::arch('-leading'))->toBeNull()
        ->and(Platform::arch(str_repeat('a', 33)))->toBeNull()
        ->and(Platform::arch('i686 (32-bit)'))->toBeNull();
});

it('mirrors the OS families core accepts', function (): void {
    // Read from `robot-council/core`'s `Support\Platform::OS_FAMILIES` on `main` after core#358.
    expect(Platform::OS_FAMILIES)->toBe(['Windows', 'Darwin', 'Linux', 'BSD', 'Solaris', 'Unknown']);
});
