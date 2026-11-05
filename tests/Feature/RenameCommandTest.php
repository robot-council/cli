<?php

declare(strict_types=1);

/**
 * `robot-council rename`: the machine renaming its own installation (#359).
 *
 * The service half is `PATCH /robot-council/api/installation` (`robot-council/core#591`). The
 * shapes faked here are the ones its controller and README give: 200 with `installation_id`,
 * `harness`, `old_label` and `new_label`; 422 with the reason in `errors.machine_label`; 409 with a
 * `message`; 401 for a credential that is not an installation's.
 *
 * @command  vendor/bin/pest --compact tests/Feature/RenameCommandTest.php
 */

use App\Support\Credentials\Credential;
use App\Support\Credentials\Credentials;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\RecordingStore;
use Tests\Fixtures\TwoStreamOutput;

const RENAME_SERVICE = 'https://fleet.example.test';
const RENAME_CREDENTIAL = 'rcouncil_1|INSTALLATION-CREDENTIAL-0123456789';

/**
 * A store holding an enrollment for the service under test, or none.
 */
function renameStore(bool $enrolled = true): void
{
    $store = new RecordingStore;

    if ($enrolled) {
        $store->put(RENAME_SERVICE.'|claude', new Credential(RENAME_CREDENTIAL));
    }

    app()->instance(Credentials::class, new Credentials([$store]));
}

/**
 * Run the command, returning the exit code and what reached each stream.
 *
 * @return array{0: int, 1: string, 2: string}
 */
function renameRun(string $label = 'work-laptop'): array
{
    $output = new TwoStreamOutput;
    $code = Artisan::call('rename', ['label' => $label, '--service' => RENAME_SERVICE], $output);

    return [$code, $output->stdout(), $output->stderr()];
}

/**
 * The service answering the rename with a status and body.
 *
 * @param  array<string, mixed>  $body
 */
function renameAnswers(int $status, array $body): void
{
    Http::fake(['*/robot-council/api/installation' => Http::response($body, $status)]);
}

beforeEach(function (): void {
    // Named, not detected: `laravel/agent-detector` reads the environment, which differs between a
    // developer's harness and CI
    putenv('ROBOT_COUNCIL_HARNESS=claude');
    Http::preventStrayRequests();
});

afterEach(function (): void {
    putenv('ROBOT_COUNCIL_HARNESS');
});

it('renames the installation with its own credential and prints both labels', function (): void {
    renameStore();
    renameAnswers(200, ['installation_id' => 7, 'harness' => 'claude', 'old_label' => 'unknown-machine', 'new_label' => 'work-laptop']);

    [$code, $stdout, $stderr] = renameRun();

    expect($code)->toBe(0)
        ->and($stdout)->toContain('Renamed this machine from unknown-machine to work-laptop.')
        ->and($stdout.$stderr)->not->toContain(RENAME_CREDENTIAL);

    // The request itself: the right verb and route, the installation credential as the bearer, the
    // label as sent, and nothing that names an installation
    Http::assertSent(fn (Request $request): bool => $request->method() === 'PATCH'
        && $request->url() === RENAME_SERVICE.'/robot-council/api/installation'
        && $request->hasHeader('Authorization', 'Bearer '.RENAME_CREDENTIAL)
        && $request->data() === ['machine_label' => 'work-laptop']);
});

it('starts no session, so no session token is ever involved', function (): void {
    renameStore();
    renameAnswers(200, ['installation_id' => 7, 'harness' => 'claude', 'old_label' => 'a', 'new_label' => 'b']);

    renameRun('b');

    Http::assertSentCount(1);
});

it('prints the service reason when it refuses the label', function (): void {
    renameStore();
    renameAnswers(422, [
        'message' => 'The machine label field format is invalid.',
        'errors' => ['machine_label' => ['The machine label may only contain letters, numbers, dots, underscores and hyphens.']],
    ]);

    [$code, $stdout, $stderr] = renameRun('work laptop!');

    expect($code)->toBe(1)
        ->and($stdout.$stderr)->toContain('The machine label may only contain letters, numbers, dots, underscores and hyphens.');
});

it('prints the service reason when another installation already holds the label', function (): void {
    renameStore();
    renameAnswers(422, ['errors' => ['machine_label' => ['Another claude installation of yours is already called work-laptop.']]]);

    [$code, $stdout, $stderr] = renameRun();

    expect($code)->toBe(1)
        ->and($stdout.$stderr)->toContain('Another claude installation of yours is already called work-laptop.');
});

it('says so when the installation already carries the label', function (): void {
    renameStore();
    renameAnswers(409, ['message' => 'This claude installation is already called work-laptop, so nothing changed.', 'installation_id' => 7, 'machine_label' => 'work-laptop']);

    [$code, $stdout, $stderr] = renameRun();

    expect($code)->toBe(1)
        ->and($stdout.$stderr)->toContain('already called work-laptop, so nothing changed');
});

it('tells a revoked or foreign credential to enroll again', function (): void {
    renameStore();
    renameAnswers(401, ['message' => 'Unauthenticated.']);

    [$code, $stdout, $stderr] = renameRun();

    expect($code)->toBe(1)
        ->and($stdout.$stderr)->toContain('Run `robot-council enroll`');
});

it('relays the 404 the service gives for a revoked installation, and names the status otherwise', function (): void {
    renameStore();
    renameAnswers(404, ['message' => 'This claude installation has been revoked, so it has no name to change.']);

    [$code, $stdout, $stderr] = renameRun();

    expect($code)->toBe(1)
        ->and($stdout.$stderr)->toContain('has been revoked');
});

it('falls back to the status for an answer it does not recognize', function (): void {
    renameStore();
    Http::fake(['*' => Http::response('', 500)]);

    [$code, $stdout, $stderr] = renameRun();

    expect($code)->toBe(1)
        ->and($stdout.$stderr)->toContain('The service answered HTTP 500.');
});

it('has words for a 429, and for a 422 or 409 that carries none', function (int $status, string $expected): void {
    renameStore();
    renameAnswers($status, []);

    [$code, $stdout, $stderr] = renameRun();

    expect($code)->toBe(1)->and($stdout.$stderr)->toContain($expected);
})->with([
    'rate limited' => [429, 'The service is rate limiting this machine.'],
    'refused with no reason' => [422, 'The service refused that label.'],
    'unchanged with no message' => [409, 'already has that label, so nothing changed'],
    'missing with no message' => [404, 'does not offer renaming yet'],
]);

it('falls back to the top-level message of a 422 that names no machine_label reason', function (): void {
    renameStore();
    renameAnswers(422, ['message' => 'The given data was invalid.', 'errors' => ['other' => ['x']]]);

    [, $stdout, $stderr] = renameRun();

    expect($stdout.$stderr)->toContain('The given data was invalid.');
});

it('reduces a reason that carries control characters before printing it', function (): void {
    renameStore();
    renameAnswers(422, ['errors' => ['machine_label' => ["bad\x1b[31m reason\r"]]]);

    [, $stdout, $stderr] = renameRun();

    expect($stdout.$stderr)->toContain('bad[31m reason')->not->toContain("\x1b");
});

it('refuses a success it cannot read rather than printing blanks', function (): void {
    renameStore();
    renameAnswers(200, ['installation_id' => 7]);

    [$code, $stdout, $stderr] = renameRun();

    expect($code)->toBe(1)
        ->and($stdout.$stderr)->toContain('did not say what it was called before and after');
});

it('says it could not reach an unreachable service without repeating the exception text', function (): void {
    renameStore();
    Http::fake(['*' => fn () => throw new ConnectionException('cURL error 6: secret-host-detail')]);

    [$code, $stdout, $stderr] = renameRun();

    expect($code)->toBe(1)
        ->and($stdout.$stderr)->toContain('Could not reach the service.')->not->toContain('secret-host-detail');
});

it('sends nothing when this machine is not enrolled', function (): void {
    renameStore(enrolled: false);

    [$code] = renameRun();

    expect($code)->toBe(1);
    Http::assertNothingSent();
});

it('needs a service', function (): void {
    renameStore();
    putenv('ROBOT_COUNCIL_SERVICE');

    $output = new TwoStreamOutput;

    expect(Artisan::call('rename', ['label' => 'work-laptop'], $output))->toBe(1)
        ->and($output->stdout().$output->stderr())->toContain('Pass --service');
    Http::assertNothingSent();
});
