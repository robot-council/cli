<?php

declare(strict_types=1);

/**
 * A session's declared capacity, on the session start and in what the service reports back (#302).
 *
 * The contract is `robot-council/core` v0.7.0's (#409): an optional `capacity` of 1 to 16 on the
 * start, with more stored as 16, and `capacity` and `declared_capacity` in the answer.
 *
 * @command  vendor/bin/pest --compact tests/Feature/CapacityTest.php
 */

use App\Support\Credentials\Credential;
use App\Support\Session;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const CAPACITY_SERVICE = 'https://fleet.example.test';

/**
 * What every session start sent, in order.
 *
 * @return list<array<array-key, mixed>>
 */
function capacityStarts(): array
{
    return array_values(Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/api/sessions'))
        ->map(fn (array $pair): array => $pair[0]->data())
        ->all());
}

/**
 * Start a session against a service answering with the given extra fields.
 *
 * @param  array<string, mixed>  $answer  What the service adds to its start response.
 */
function capacitySession(?int $capacity, array $answer = []): Session
{
    Http::fake(['*/api/sessions' => Http::response(['session_id' => 5, 'token' => 'rcouncil_2|T', 'expires_in' => 3600, ...$answer], 201)]);

    $session = new Session(app(Factory::class), CAPACITY_SERVICE, new Credential('rcouncil_1|CAPACITY'));
    $session->start(null, 'robot-council/cli', 'primary', capacity: $capacity);

    return $session;
}

beforeEach(function (): void {
    Http::preventStrayRequests();
});

it('sends a declared capacity with the session start', function (): void {
    capacitySession(3);

    expect(capacityStarts()[0]['capacity'] ?? null)->toBe(3);
});

it('sends nothing when no capacity is declared, so the service default applies', function (): void {
    capacitySession(null);

    expect(capacityStarts()[0])->not->toHaveKey('capacity');
});

it('reads back the capacity in effect and the one declared', function (): void {
    // Core stores a declaration past its maximum of 16 as 16, and reads it back that way
    $session = capacitySession(20, ['capacity' => 1, 'declared_capacity' => 16]);

    expect($session->capacity())->toBe(1)
        ->and($session->declaredCapacity())->toBe(16);
});

it('reads no capacity from a service that predates it', function (): void {
    $session = capacitySession(3);

    expect($session->capacity())->toBeNull()
        ->and($session->declaredCapacity())->toBeNull();
});
