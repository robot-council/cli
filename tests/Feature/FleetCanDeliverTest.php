<?php

declare(strict_types=1);

/**
 * Whether anything on this fleet can reach an agent that is waiting.
 *
 * **The symptom is an absence, which is why it needs saying out loud.** A hook that finds an empty
 * sink and a fleet that genuinely has nothing to say are byte-identical from the agent's side. A
 * fleet where no installation holds `coordinator:direct` is permanently in the first state and
 * looks exactly like the second (cli#113).
 *
 * **The hard part is not warning the common case.** A bridge that only ever receives holds none of
 * `coordinator:direct` and is correctly configured. A check on the session's own abilities would
 * fire for almost every session, which is how a warning stops being read -- so what is reported is
 * the FLEET's answer, and `robot-council/core#159` is where that answer came from.
 *
 * @command  vendor/bin/pest tests/Feature/FleetCanDeliverTest.php
 */

use App\Support\Credentials\Credential;
use App\Support\FleetDelivery;
use App\Support\Session;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    // **An unmatched URL must fail loudly rather than go to the network.** With the array form of
    // `Http::fake`, a request nothing stubs is passed through to the real handler -- so a path
    // regression would surface as a swallowed `ConnectionException` after a real DNS attempt, which
    // reads as a logic failure and costs ten seconds. `BridgeTest` already does this.
    Http::preventStrayRequests();
});

/**
 * A started session talking to a faked service.
 *
 * **One `Http::fake()` per case, and that is the whole point of the parameter.** `Http::fake()`
 * MERGES its stubs and the first registered match wins, so an earlier draft that chained four of
 * these through `->and()` in one test delivered the FIRST body to all four -- the 500, the 401 and
 * the non-boolean were never seen. Measured: with a 200 registered first the client answers 200,
 * and with the 500 first it answers 500.
 *
 * @param  array<string, mixed>|null  $describe  The `GET agent/session` body, or null for a refusal.
 * @param  int  $describeStatus  The status that endpoint answers with.
 * @return Session The started session.
 */
function sessionDescribedBy(?array $describe, int $describeStatus = 200): Session
{
    Http::fake([
        '*/api/agent/session' => $describe === null
            ? Http::response('', $describeStatus)
            : Http::response($describe, $describeStatus),
        '*/api/sessions' => Http::response([
            'session_id' => 42,
            'token' => 'session-token-for-the-test',
            'abilities' => ['tasks:create'],
            'expires_in' => 3600,
        ], 201),
    ]);

    $session = new Session(app(Factory::class), 'https://council.test', new Credential('installation-credential'));

    $session->start('robot-council/cli');

    return $session;
}

it('reports a fleet that cannot deliver', function (): void {
    expect(sessionDescribedBy(['fleet_can_direct' => false])->fleetCanDirect())->toBeFalse();
});

it('reports a fleet that can deliver, so the finding is not its resting state', function (): void {
    expect(sessionDescribedBy(['fleet_can_direct' => true])->fleetCanDirect())->toBeTrue();
});

it('answers null when the service sends no such field', function (): void {
    // An older service predating `robot-council/core#159`. Reporting its silence as "nothing will
    // ever arrive" would be a false alarm about the one thing this exists to report truthfully.
    expect(sessionDescribedBy(['session_id' => 42])->fleetCanDirect())->toBeNull();
});

it('answers null when the field is not a boolean', function (): void {
    // Its own test, because `Http::fake` merges stubs and chaining these left three of them
    // answered by the first. Without the `is_bool` check this returns the string from a `?bool`
    // method -- a `TypeError` thrown outside the try, which would escape into the MCP protocol
    // stream that the command's own docblock says must carry nothing but the protocol.
    expect(sessionDescribedBy(['fleet_can_direct' => 'yes'])->fleetCanDirect())->toBeNull();
});

it('answers null when the request is refused', function (): void {
    expect(sessionDescribedBy(null, 500)->fleetCanDirect())->toBeNull();
});

it('does not read a usable-looking body out of a failed response', function (): void {
    // **The only input that pins the status guard**, and an earlier version of this file did not
    // have it: a refusal with an EMPTY body is answered `null` whether the guard runs or not,
    // because the body then fails the `is_array` check anyway. Removing the guard left the suite
    // green until this case existed.
    //
    // A 5xx carrying a JSON body is what a proxy or an error handler rendering JSON produces. The
    // answer inside it is not this fleet's answer, and reporting it as one would have the client
    // act on a number the service did not stand behind.
    expect(sessionDescribedBy(['fleet_can_direct' => true], 500)->fleetCanDirect())->toBeNull()
        ->and(sessionDescribedBy(['fleet_can_direct' => false], 503)->fleetCanDirect())->toBeNull();
});

it('answers null when the session token is not accepted', function (): void {
    expect(sessionDescribedBy(null, 401)->fleetCanDirect())->toBeNull();
});

it('asks with a GET, on the right path, carrying the session token', function (): void {
    // **Nothing else in this file can see any of that.** `Http::fake` matches on URL alone, never
    // on method or headers, so `->get()` becoming `->post()` -- a 405 against the real service,
    // since core registers `Route::get('agent/session')` only -- or `request()` becoming the
    // installation credential -- a 401 from `EnsureAgentSession` -- would leave every other test
    // green while the feature is silently dead. `BridgeTest` treats the credential half as
    // load-bearing for the same reason.
    sessionDescribedBy(['fleet_can_direct' => false])->fleetCanDirect();

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://council.test/robot-council/api/agent/session'
        && $request->hasHeader('Authorization', 'Bearer session-token-for-the-test'));
});

it('answers null for a session that was never started, rather than asking', function (): void {
    // No token means no request to make. `null` rather than `false` for the same reason as above,
    // and `Session::allows()` reads an unstarted session the same way.
    Http::fake(['*' => Http::response(['fleet_can_direct' => false])]);

    $session = new Session(app(Factory::class), 'https://council.test', new Credential('installation-credential'));

    expect($session->fleetCanDirect())->toBeNull();

    Http::assertNothingSent();
});

it('says what to do only when the fleet definitely cannot deliver', function (): void {
    // **This replaced a skipped test, and the reason is worth stating.** The first version drove
    // `robot-council mcp` and asserted the line on stderr; that command runs an MCP stdio loop no
    // test drives, so it could only be shipped skipped -- which reads as coverage and is not. The
    // decision and the wording moved into `FleetDelivery` so they can be asserted, leaving two
    // lines of wiring uncovered instead of the rule.
    $warning = FleetDelivery::warning(false);

    expect($warning)->toBeString()
        // **Names a running session in a role, not an installation holding an ability.**
        // `robot-council/core#222` moved `coordinator:direct` onto a session's role and
        // `robot-council/core#223` moved this field to match, so the old wording described a
        // mechanism that no longer decides anything.
        ->and($warning)->toContain('coordinator')
        ->and($warning)->toContain('running')
        ->and($warning)->not->toContain('installation')
        // **And does not send anybody to `robot-council:grant-ability`.** It writes a column no
        // session's abilities are read from, so an operator following that advice would fix
        // something and see nothing change. The administration page is where a role is decided.
        ->and($warning)->not->toContain('grant-ability')
        ->and($warning)->toContain('administration page')
        // Says the reading is a snapshot, because the answer flips when the one coordinator
        // restarts and this line is emitted once.
        ->and($warning)->toContain('at startup')
        // **And claims only what is gated.** An earlier version ended "a stop hook here will never
        // find anything waiting", which `FleetFollower` refutes: it delivers `lock.taken_over` to
        // the session a lease was taken from, and an ordinary takeover needs only `locks:acquire`.
        ->and($warning)->not->toContain('never');
});

it('says nothing for a fleet that can deliver, nor for one that did not answer', function (): void {
    // The two non-findings, kept apart from each other and from the finding. `null` is the case
    // that matters most: an older service, a refusal, or an unparseable body must not be reported
    // as "nothing will ever arrive".
    expect(FleetDelivery::warning(true))->toBeNull()
        ->and(FleetDelivery::warning(null))->toBeNull();
});

it('does not warn a receive-only session on a fleet that can deliver', function (): void {
    // **The criterion that decides whether this is worth having.** This session holds no
    // `coordinator:direct` -- the ordinary enrollment -- and is correctly configured. Warning here
    // is the failure mode the ticket was filed to avoid.
    $session = sessionDescribedBy(['fleet_can_direct' => true]);

    expect($session->allows('coordinator:direct'))->toBeFalse()
        ->and($session->fleetCanDirect())->toBeTrue()
        // **The conclusion the name promises, rather than its inputs.** An earlier version asserted
        // only the two facts above and left "does not warn" to a different test.
        ->and(FleetDelivery::warning($session->fleetCanDirect()))->toBeNull();
});
