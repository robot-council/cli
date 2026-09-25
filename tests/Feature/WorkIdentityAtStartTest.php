<?php

declare(strict_types=1);

/**
 * What a starting session tells the service about where the work is.
 *
 * The service splits a `project_id` into a repository and a work location **only when the client
 * names neither**, so a pair that is half explicit must still be whole when it is sent. That is the
 * rule these tests exist to hold: what is known is sent, what is not known is omitted, and nothing
 * is sent that the service would refuse.
 *
 * @command  vendor/bin/pest --compact tests/Feature/WorkIdentityAtStartTest.php
 */

use App\Support\Checkout;
use App\Support\Credentials\Credential;
use App\Support\Session;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const WORK_SERVICE = 'https://fleet.test';
const WORK_INSTALLATION = 'installation-token';

/**
 * The work identity the one session-start request carried, without the platform beside it.
 *
 * @return array<string, mixed>
 */
function startPayload(): array
{
    $sent = [];

    Http::assertSent(function (Request $request) use (&$sent): bool {
        if ($request->method() === 'POST' && str_ends_with($request->url(), '/api/sessions')) {
            $sent = $request->data();
        }

        return true;
    });

    // The platform rides on every start (#266) and has its own tests in `SessionPlatformTest`;
    // what these pin is the work identity beside it.
    unset($sent['platform']);

    /** @var array<string, mixed> $sent */
    return $sent;
}

beforeEach(function (): void {
    Http::preventStrayRequests();

    Http::fake([
        '*/api/sessions' => Http::response([
            'session_id' => 1,
            'token' => 'session-token',
            'expires_in' => 3600,
            'abilities' => ['tasks:create', 'events:post'],
        ], 201),
    ]);
});

it('sends both fields when both are known', function (): void {
    $session = new Session(app(Factory::class), WORK_SERVICE, new Credential(WORK_INSTALLATION));

    $session->start('UAMS-Web/uams-statamic/a', 'UAMS-Web/uams-statamic', 'a');

    expect(startPayload())->toBe([
        'project_id' => 'UAMS-Web/uams-statamic/a',
        'repository' => 'UAMS-Web/uams-statamic',
        'work_location' => 'a',
    ]);
});

it('omits what is not known rather than sending an empty value', function (): void {
    $session = new Session(app(Factory::class), WORK_SERVICE, new Credential(WORK_INSTALLATION));

    $session->start(null, 'UAMS-Web/uams-statamic');

    // The key is absent, not null and not an empty string: `filled()` on the service decides
    // whether a field was named at all.
    expect(startPayload())->toBe(['repository' => 'UAMS-Web/uams-statamic']);
});

it('sends nothing extra when nothing is known, as an un-upgraded client does', function (): void {
    $session = new Session(app(Factory::class), WORK_SERVICE, new Credential(WORK_INSTALLATION));

    $session->start('UAMS-Web/uams-statamic');

    // Naming neither is what lets the service split the old label, which is how a client that has
    // not been upgraded keeps working.
    expect(startPayload())->toBe(['project_id' => 'UAMS-Web/uams-statamic']);
});

it('sends a work location of 0, which a falsy filter would have dropped', function (): void {
    $session = new Session(app(Factory::class), WORK_SERVICE, new Credential(WORK_INSTALLATION));

    $session->start(null, 'owner/name', '0');

    // `0` matches the service's charset, so a worktree may be called that. A bare `array_filter`
    // drops `'0'` along with null, and the directory would have reported no location at all.
    expect(startPayload())->toBe(['repository' => 'owner/name', 'work_location' => '0']);
});

it('prefers a written value over a derived one, for each field independently', function (): void {
    // Derived from this checkout, which is a real repository with a real remote.
    [$derivedRepository, $derivedLocation] = Checkout::resolve(null, null);

    [$repository, $location] = Checkout::resolve('Named/Repository', null);

    expect($repository)->toBe('Named/Repository')
        // Naming one does not suppress the other, which matters because the service will not fill
        // the second in from `project_id` once the first has been named.
        ->and($location)->toBe($derivedLocation);

    [$repository, $location] = Checkout::resolve(null, 'named-location');

    expect($location)->toBe('named-location')
        ->and($repository)->toBe($derivedRepository);
});

it('judges a written value by the same rules as a derived one', function (): void {
    // Normalized, because an operator who writes `Feature-A` means `feature-a` and the service
    // refuses the first.
    expect(Checkout::resolve(null, 'Feature-A')[1])->toBe('feature-a')
        ->and(Checkout::resolve(null, '-wip')[1])->toBe('wip')
        // Rejected, because a repository name is GitHub's and a changed one names a different
        // repository. Writing it out is not a reason to send something the service will refuse.
        ->and(Checkout::resolve('not-a-repository', null)[0])->toBeNull()
        ->and(Checkout::resolve('-leading/hyphen', null)[0])->toBeNull()
        ->and(Checkout::resolve('../evil', null)[0])->toBeNull();
});

it('gives a session the same abilities whichever repository it names', function (): void {
    // **This is a property of the service, and this test cannot prove it.** What it can prove is
    // the client half: nothing here derives, filters, or varies an ability by where the work is.
    // The fake answers the same abilities to both calls, so a failure here means this application
    // grew logic keyed on the repository -- which is the mistake worth catching, since a derived
    // value is the client asserting something about itself and must never decide what it may do.
    $first = new Session(app(Factory::class), WORK_SERVICE, new Credential(WORK_INSTALLATION));
    $first->start(null, 'UAMS-Web/uams-statamic', 'a');

    $second = new Session(app(Factory::class), WORK_SERVICE, new Credential(WORK_INSTALLATION));
    $second->start(null, 'Someone-Else/private-thing', 'ci');

    expect($first->allows('tasks:create'))->toBe($second->allows('tasks:create'))
        ->and($first->allows('coordinator:direct'))->toBe($second->allows('coordinator:direct'));
});

it('treats an empty flag as absent rather than as a value', function (): void {
    [$derivedRepository, $derivedLocation] = Checkout::resolve(null, null);

    expect(Checkout::resolve('', '')[0])->toBe($derivedRepository)
        ->and(Checkout::resolve('   ', '   ')[1])->toBe($derivedLocation);
});
