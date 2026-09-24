<?php

declare(strict_types=1);

/**
 * Enrolling this machine, and where the credential ends up.
 *
 * **The assertions that matter most are the negative ones.** This command exists because a token
 * obtained inside an agent's shell becomes tool output, and tool output becomes transcript. So the
 * tests that earn their place are the ones searching captured output for the credential, rather
 * than reading the code and agreeing with it.
 *
 * @command  vendor/bin/pest --compact tests/Feature/EnrollCommandTest.php
 */

use App\Support\Credentials\Credentials;
use Carbon\CarbonInterval;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\Fixtures\RecordingStore;

const SERVICE = 'https://fleet.example.test';
const CREDENTIAL = 'rcouncil_1|SuPeRsEcReTcReDeNtIaLvAlUe0123456789abcdef';

/**
 * Every file under a directory, at any depth.
 *
 * @return list<string> The paths.
 */
function filesUnder(string $directory): array
{
    /** @var list<string> $found */
    $found = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    );

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        $found[] = $file->getPathname();
    }

    sort($found);

    return $found;
}

/**
 * Bind a recording store in place of whatever this machine would really use.
 */
function recordingStore(bool $fails = false): RecordingStore
{
    $store = new RecordingStore($fails);

    app()->instance(Credentials::class, new Credentials([$store]));

    return $store;
}

/**
 * The service's two responses, with the approval arriving after `$pending` polls.
 *
 * @param  int  $pending  How many times the exchange answers `authorization_pending` first.
 * @param  array<string, mixed>|null  $tokenOverride  A different credential body, where a test needs one.
 */
function fakeService(int $pending = 0, ?array $tokenOverride = null): void
{
    $exchanges = array_fill(0, $pending, Http::response(['error' => 'authorization_pending'], 400));
    $exchanges[] = Http::response($tokenOverride ?? [
        'token' => CREDENTIAL,
        'abilities' => ['sessions:start'],
        // Kept although nothing reads it, and kept deliberately: a deployed service still sends
        // this key, so a fixture without it would run every test in this file against a response
        // shape no service produces. `it treats a response carrying granted_abilities identically`
        // is what makes it evidence rather than decoration.
        'granted_abilities' => ['tasks:create', 'tasks:claim', 'events:post'],
        'expires_in' => 2592000,
    ], 201);

    Http::fake([
        '*/api/device/code' => Http::response([
            'device_code' => 'a-device-code',
            'user_code' => 'HFTGPSTW',
            'verification_uri' => SERVICE.'/robot-council/enroll',
            'expires_in' => 600,
            'interval' => 5,
        ], 201),
        '*/api/device/token' => Http::sequence($exchanges),
    ]);
}

beforeEach(function (): void {
    Sleep::fake();

    // A fake that stops matching otherwise makes a REAL request, so a test can pass by reaching
    // the network rather than by exercising what it claims to
    Http::preventStrayRequests();
});

it('prints the user code and where to approve it, and nothing else of substance', function (): void {
    fakeService();
    recordingStore();

    expect(Artisan::call('enroll', ['--service' => SERVICE, '--harness' => 'claude']))->toBe(0);

    $output = Artisan::output();

    expect($output)->toContain('HFTGPSTW')
        ->toContain(SERVICE.'/robot-council/enroll');
});

it('treats a response carrying granted_abilities identically to one without it', function (): void {
    // The key decides nothing since `robot-council/core#222`, and `robot-council/core#239` removes
    // it from the response. That removal must not be a compatibility event for this client, which
    // is a claim about two runs rather than about one -- so both are run and their output compared,
    // byte for byte.
    $credential = [
        'token' => CREDENTIAL,
        'abilities' => ['sessions:start'],
        'expires_in' => 2592000,
    ];

    // **One `Http::fake()` with a two-response sequence, and neither run is selected by a captured
    // flag.** Two things forced this shape, both measured here:
    //
    // - `Http::fake()` MERGES its stubs into the ones already registered, so calling `fakeService()`
    //   a second time leaves the first call's sequence in place and matching -- and a sequence with
    //   nothing left in it raises rather than falling through. The second run exited 1 against an
    //   unchanged command.
    // - The first fix was a `$carriesTheKey` flag captured by reference, and **Rector deleted it**:
    //   it dropped the `use (&$carriesTheKey)` and folded `if ($carriesTheKey)` away as always true,
    //   so both runs sent the key and this test compared a run against itself. It still passed. The
    //   gate reported `369 passed` either way, which is the whole reason the shape matters.
    Http::fake([
        '*/api/device/code' => Http::response([
            'device_code' => 'a-device-code',
            'user_code' => 'HFTGPSTW',
            'verification_uri' => SERVICE.'/robot-council/enroll',
            'expires_in' => 600,
            'interval' => 5,
        ], 201),
        '*/api/device/token' => Http::sequence()
            ->push($credential + ['granted_abilities' => ['tasks:create', 'tasks:claim', 'events:post']], 201)
            ->push($credential, 201),
    ]);

    $run = function (): string {
        recordingStore();

        expect(Artisan::call('enroll', ['--service' => SERVICE, '--harness' => 'claude']))->toBe(0);

        return Artisan::output();
    };

    // In sequence order: the first run is served the key, the second is not.
    $with = $run();
    $without = $run();

    expect($with)->toBe($without);

    // The control. Without it, a command that printed nothing at all -- or a fake that never
    // answered -- would satisfy the comparison above by producing two empty strings.
    expect($with)->toContain('This machine is enrolled.');

    // And the abilities themselves are absent, which the comparison alone does not say: a command
    // that printed the list in BOTH runs, from a hard-coded copy, would compare equal.
    expect($with)->not->toContain('tasks:create')
        ->not->toContain('will carry');
});

it('never puts the credential on stdout or stderr', function (): void {
    fakeService();
    $store = recordingStore();

    Artisan::call('enroll', ['--service' => SERVICE, '--harness' => 'claude']);

    // Searched for in the captured output rather than argued from the source. The control is the
    // line below: the same value IS in the store, so a run that stored nothing could not pass this
    // pair by printing nothing.
    expect(Artisan::output())->not->toContain(CREDENTIAL)
        ->and($store->stored[SERVICE.'|claude']->reveal())->toBe(CREDENTIAL);
});

it('writes nothing anywhere inside a repository', function (): void {
    fakeService();
    recordingStore();

    // A real temporary repository, entered for the run, as the ticket asks. `scandir` on the
    // working directory was the first version of this and could not see a write one level down --
    // measured: a file created in an existing subdirectory left `array_diff` empty while the file
    // demonstrably existed. This walks the tree instead.
    $repository = sys_get_temp_dir().'/rc-repo-'.bin2hex(random_bytes(6));

    mkdir($repository.'/src', 0700, true);

    $previous = getcwd();

    chdir($repository);

    $before = filesUnder($repository);

    try {
        Artisan::call('enroll', ['--service' => SERVICE, '--harness' => 'claude']);
    } finally {
        chdir($previous ?: sys_get_temp_dir());
    }

    $after = filesUnder($repository);

    expect(array_diff($after, $before))->toBeEmpty();

    // Not `rm -rf`, which is not a command under Windows PHP
    $leftovers = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($repository, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    /** @var SplFileInfo $leftover */
    foreach ($leftovers as $leftover) {
        $leftover->isDir() ? rmdir($leftover->getPathname()) : unlink($leftover->getPathname());
    }

    rmdir($repository);
});

it('sends the hash of the verifier, never the verifier, and a reduced label', function (): void {
    fakeService();
    recordingStore();

    Artisan::call('enroll', [
        '--service' => SERVICE,
        '--harness' => 'Claude Code',
        '--machine-label' => 'Josh laptop!!.local',
    ]);

    Http::assertSent(function (Request $request): bool {
        if (! str_contains($request->url(), '/api/device/code')) {
            return true;
        }

        /** @var array<string, mixed> $body */
        $body = $request->data();

        // The challenge is a SHA-256, so 64 hex characters, and the verifier itself never leaves
        // this process -- which is the whole point of PKCE here
        expect($body['code_challenge'])->toMatch('/^[0-9a-f]{64}$/')

            // Reduced BEFORE it is sent, so the service never has to refuse it
            ->and($body['harness'])->toBe('claudecode')
            ->and($body['machine_label'])->toBe('Joshlaptop');

        return true;
    });
});

it('lets --harness override what detection would have said', function (): void {
    fakeService();
    recordingStore();

    // Detection reads environment variables a harness sets. The flag has to win, because a
    // developer correcting a wrong guess should not have to argue with it.
    putenv('CLAUDECODE=1');

    Artisan::call('enroll', ['--service' => SERVICE, '--harness' => 'codex']);

    putenv('CLAUDECODE');

    Http::assertSent(function (Request $request): bool {
        if (str_contains($request->url(), '/api/device/code')) {
            /** @var array<string, mixed> $body */
            $body = $request->data();

            expect($body['harness'])->toBe('codex');
        }

        return true;
    });
});

it('refuses a service that is not reached over https', function (): void {
    recordingStore();

    $code = Artisan::call('enroll', ['--service' => 'http://fleet.example.test', '--harness' => 'claude']);

    // A thirty-day bearer token and the PKCE verifier both cross this connection
    expect($code)->toBe(1)
        ->and(Artisan::output())->toContain('https');
});

it('allows http only for a loopback address', function (): void {
    fakeService();
    $store = recordingStore();

    // A developer running core locally has no certificate, and cleartext to their own machine
    // carries nothing off the host
    expect(Artisan::call('enroll', ['--service' => 'http://127.0.0.1:8000', '--harness' => 'claude']))->toBe(0)
        ->and($store->stored)->toHaveKey('http://127.0.0.1:8000|claude');
});

it('polls while the developer has not decided yet', function (): void {
    fakeService(pending: 3);
    $store = recordingStore();

    expect(Artisan::call('enroll', ['--service' => SERVICE, '--harness' => 'claude']))->toBe(0)
        ->and($store->stored)->toHaveKey(SERVICE.'|claude');

    // `authorization_pending` is the flow working, not a failure, and the interval slept is the one
    // the service asked for rather than a number this command chose
    Sleep::assertSlept(fn (CarbonInterval $duration): bool => $duration->totalSeconds === 5.0, times: 3);
});

it('stops rather than polling a code that is dead', function (string $error, string $expected): void {
    Http::fake([
        '*/api/device/code' => Http::response([
            'device_code' => 'a-device-code',
            'user_code' => 'HFTGPSTW',
            'verification_uri' => SERVICE.'/robot-council/enroll',
            'expires_in' => 600,
            'interval' => 5,
        ], 201),
        '*/api/device/token' => Http::response(['error' => $error], 400),
    ]);

    $store = recordingStore();

    $code = Artisan::call('enroll', ['--service' => SERVICE, '--harness' => 'claude']);
    $output = Artisan::output();

    expect($code)->toBe(1)
        ->and($output)->toContain($expected)
        ->and($store->stored)->toBeEmpty();

    Sleep::assertNeverSlept();
})->with([
    'denied' => ['access_denied', 'denied'],
    'expired' => ['expired_token', 'expired'],
]);

it('refuses to run without a service to enroll against', function (): void {
    recordingStore();

    expect(Artisan::call('enroll', ['--harness' => 'claude']))->toBe(1)
        ->and(Artisan::output())->toContain('--service');
});

it('reports a store that could not hold the credential, without printing it', function (): void {
    fakeService();
    recordingStore(fails: true);

    $code = Artisan::call('enroll', ['--service' => SERVICE, '--harness' => 'claude']);

    // Captured ONCE: `Artisan::output()` drains its buffer, so a second call reads empty and an
    // assertion made against it passes for that reason rather than for the right one
    $output = Artisan::output();

    expect($code)->toBe(1)
        ->and($output)->not->toContain(CREDENTIAL)
        ->and($output)->toContain('could not be stored')

        // **And what happened before it (cli#207).** The fleet approved this machine already, so
        // "could not be stored" alone leaves an operator unsure whether to approve again
        ->and($output)->toContain('This machine was approved')
        ->and($output)->toContain('Run `robot-council enroll` again');
});
