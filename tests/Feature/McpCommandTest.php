<?php

declare(strict_types=1);

/**
 * What the bridge command does about the session it started.
 *
 * **A session must not outlive the harness, and must not be ended twice.** Ending zero times leaves
 * it holding whatever it claimed until the presence sweep marks it gone half an hour later. Ending
 * twice sends a second request against a token the first one invalidated, which comes back 401 --
 * the same status the bridge reports as `This machine is not enrolled, or its credential was
 * revoked.`, naming the wrong cause entirely.
 *
 * Counted against the faked endpoint rather than inferred from an exit code, because an exit code
 * is the same whether the session was ended once, twice, or not at all.
 *
 * @command  vendor/bin/pest --compact tests/Feature/McpCommandTest.php
 */
use App\Support\Bridge;
use App\Support\Credentials\Credential;
use App\Support\Credentials\Credentials;
use App\Support\PendingEvents;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\RecordingStore;
use Tests\Fixtures\TwoStreamOutput;

const MCP_SERVICE = 'https://bridge.example.test';
const MCP_INSTALLATION = 'rcouncil_1|INSTALLATION-FOR-THE-BRIDGE';
const MCP_SESSION = 42;

beforeEach(function (): void {
    // Named rather than detected: the detector reads the environment, and this suite runs inside a
    // harness on a developer's machine and inside none on CI.
    putenv('ROBOT_COUNCIL_HARNESS=claude');

    // A directory this test owns. The command clears a sink on the way out, and a suite that
    // reaches into a developer's real `~/.local/state` to do it is a suite nobody runs twice.
    $this->stateDirectory = sys_get_temp_dir().'/rc-mcp-'.bin2hex(random_bytes(6));

    putenv('XDG_STATE_HOME='.$this->stateDirectory);

    // A Claude Code bridge now reads the user's settings for a stop hook (#306); point it at an
    // empty directory so a run reads nothing of the developer's own
    putenv('CLAUDE_CONFIG_DIR='.$this->stateDirectory.'/claude');

    // An unmatched URL must fail loudly rather than reach the network.
    Http::preventStrayRequests();
});

afterEach(function (): void {
    array_map(unlink(...), glob($this->stateDirectory.'/robot-council/pending/*') ?: []);
    @rmdir($this->stateDirectory.'/robot-council/pending');
    @rmdir($this->stateDirectory.'/robot-council');
    @rmdir($this->stateDirectory);

    putenv('XDG_STATE_HOME');
    putenv('CLAUDE_CONFIG_DIR');
    putenv('ROBOT_COUNCIL_HARNESS');
});

/**
 * The sink the command opens for the identity these tests join under.
 */
function bridgeSink(): PendingEvents
{
    return new PendingEvents(MCP_SERVICE, 'claude', 'probe');
}

/**
 * A credential store already holding an enrollment for the service under test.
 */
function bridgeEnrolled(): void
{
    $store = new RecordingStore;

    $store->put(MCP_SERVICE.'|claude', new Credential(MCP_INSTALLATION));

    app()->instance(Credentials::class, new Credentials([$store]));
}

/**
 * A store holding nothing, so the command cannot choose an installation.
 */
function bridgeUnenrolled(): void
{
    app()->instance(Credentials::class, new Credentials([new RecordingStore]));
}

/**
 * How many times the session-end endpoint was called.
 */
function bridgeEndCalls(): int
{
    // **`recorded()`, not `assertSent()`.** The latter fails outright when nothing was recorded at
    // all, which is precisely what two of these tests are about: a bridge that never started a
    // session sends no requests, and this must be able to report zero rather than fail.
    return Http::recorded(
        fn (Request $request): bool => $request->method() === 'DELETE'
            && str_contains($request->url(), '/api/sessions/'.MCP_SESSION)
    )->count();
}

/**
 * How many times a session was started.
 */
function bridgeStartCalls(): int
{
    return Http::recorded(
        fn (Request $request): bool => $request->method() === 'POST'
            && str_ends_with($request->url(), '/api/sessions')
    )->count();
}

/**
 * The service answering a session start, a describe, and an end.
 */
function bridgeService(int $startStatus = 201): void
{
    Http::fake([
        '*/api/sessions/*' => Http::response('', 204),
        '*/api/sessions' => $startStatus === 201
            ? Http::response([
                'session_id' => MCP_SESSION,
                'token' => 'rcouncil_2|SESSION-TOKEN',
                'abilities' => ['tasks:create'],
                'expires_in' => 3600,
            ], 201)
            : Http::response(['message' => 'no'], $startStatus),
        '*/api/agent/session' => Http::response(['fleet_can_direct' => true], 200),
        '*/api/agent/role' => Http::response(['session_id' => MCP_SESSION, 'pending' => true, 'requested_role' => 'coordinator', 'role' => 'build'], 202),
        '*' => Http::response('', 200),
    ]);
}

it('starts no session and ends none when nobody joins', function (): void {
    bridgeEnrolled();
    bridgeService();

    // **Opening an editor is not a decision to join a fleet (cli#127).** Stdin is already at end
    // of file under a test runner, so this is a harness that launched the bridge and went away
    // without anybody asking it to join.
    $exit = Artisan::call('mcp', ['--service' => MCP_SERVICE]);

    expect($exit)->toBe(0)
        ->and(bridgeStartCalls())->toBe(0)
        ->and(bridgeEndCalls())->toBe(0)

        // Not even the describing call the fleet-cannot-deliver line needs: that moved to the join
        ->and(Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/api/agent/session'))->count())->toBe(0);
});

it('ends the session exactly once when stdin closes', function (): void {
    bridgeEnrolled();
    bridgeService();

    // **Stdin is already at end of file under a test runner**, which is exactly the case this
    // asserts: the reader's child sees it immediately, exits, and the bridge's loop returns. That
    // is the ordinary shutdown -- the harness went away -- reached without waiting for anything.
    $exit = Artisan::call('mcp', ['--service' => MCP_SERVICE, '--auto-join' => true]);

    expect($exit)->toBe(0)
        ->and(bridgeStartCalls())->toBe(1)
        // Once. Not zero, which leaves the session holding its work until the sweep; not twice,
        // which sends a request against an invalidated token and reports the wrong cause.
        ->and(bridgeEndCalls())->toBe(1);
});

it('leaves no reader child behind', function (): void {
    bridgeEnrolled();
    bridgeService();

    Artisan::call('mcp', ['--service' => MCP_SERVICE]);

    // **Settled rather than snapshotted.** Process teardown is not instantaneous, and this counts
    // every reader on the machine, so a child from the test that ran just before -- the order is
    // random -- can still be exiting. Measured: one run in two failed on an instant read. A short
    // bound still fails outright on a genuine leak, which does not go away by waiting.
    $running = readersRunning();

    for ($attempt = 0; $attempt < 30 && $running > 0; $attempt++) {
        usleep(100_000);

        $running = readersRunning();
    }

    expect($running)->toBe(0);
})->skip(
    fn (): bool => ! in_array(PHP_OS_FAMILY, ['Windows', 'Linux'], true),
    'Counting another process is implemented here for Windows and Linux only.'
);

it('comes up and ends no session when it could not choose an installation', function (): void {
    bridgeUnenrolled();
    bridgeService();

    $exit = Artisan::call('mcp', ['--service' => MCP_SERVICE, '--auto-join' => true]);

    // **Up rather than dead (cli#127).** A machine with no credential used to exit at launch with a
    // line most harnesses bury; the join failing now leaves the bridge serving `join`, whose
    // result names what is missing. Nothing was started, so nothing may be ended.
    expect($exit)->toBe(0)
        ->and(bridgeStartCalls())->toBe(0)
        ->and(bridgeEndCalls())->toBe(0);
});

it('ends no session when the service refuses to start one', function (): void {
    bridgeEnrolled();
    bridgeService(startStatus: 401);

    $exit = Artisan::call('mcp', ['--service' => MCP_SERVICE, '--auto-join' => true]);

    expect($exit)->toBe(0)
        ->and(bridgeStartCalls())->toBe(1)
        ->and(bridgeEndCalls())->toBe(0);
});

it('writes nothing to stdout when no message ever arrives', function (): void {
    bridgeEnrolled();
    bridgeService();

    Artisan::call('mcp', ['--service' => MCP_SERVICE]);

    // **A harness parses this stream.** The diagnostic about fleet delivery, the reader's child,
    // and the shutdown all have somewhere else to go; anything that reached stdout here would be
    // read as a malformed protocol message. #122 measured the same property against a live fleet.
    expect(Artisan::output())->toBeEmpty();
});

/**
 * How many reader children are running.
 */
function readersRunning(): int
{
    if (PHP_OS_FAMILY === 'Linux') {
        $running = 0;

        foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $path) {
            $raw = @file_get_contents($path);

            if (is_string($raw) && str_contains($raw, 'mcp:reader')) {
                $running++;
            }
        }

        return $running;
    }

    $script = sys_get_temp_dir().'/rc-mcp-'.bin2hex(random_bytes(6)).'.ps1';

    file_put_contents(
        $script,
        '(Get-CimInstance Win32_Process | Where-Object { $_.CommandLine -like "*mcp:reader*" } | Measure-Object).Count'
    );

    $output = shell_exec(sprintf(
        'powershell -NoProfile -NonInteractive -ExecutionPolicy Bypass -File %s',
        escapeshellarg($script)
    ));

    @unlink($script);

    return (int) trim((string) $output);
}

it('asks for a role named with --auto-join, which is a request and not a grant', function (): void {
    bridgeEnrolled();
    bridgeService();

    Artisan::call('mcp', ['--service' => MCP_SERVICE, '--auto-join' => true, '--role' => 'coordinator']);

    $asked = Http::recorded(
        fn (Request $request): bool => $request->method() === 'POST'
            && str_ends_with($request->url(), '/api/agent/role')
            && $request['role'] === 'coordinator'
    )->count();

    // **A separate call, because session start takes no role** and drops one it is sent
    expect($asked)->toBe(1)
        ->and(Http::recorded(
            fn (Request $request): bool => str_ends_with($request->url(), '/api/sessions')
                && array_key_exists('requested_role', (array) json_decode($request->body(), true))
        )->count())->toBe(0);
});

it('asks for no role when --auto-join names build, which every session starts as', function (): void {
    bridgeEnrolled();
    bridgeService();

    Artisan::call('mcp', ['--service' => MCP_SERVICE, '--auto-join' => true, '--role' => 'build']);

    expect(Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/api/agent/role'))->count())->toBe(0)
        ->and(bridgeStartCalls())->toBe(1);
});

it('describes the fleet at the join, not at launch', function (): void {
    bridgeEnrolled();
    bridgeService();

    Artisan::call('mcp', ['--service' => MCP_SERVICE, '--auto-join' => true]);

    expect(Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/api/agent/session'))->count())->toBe(1)

        // **Still on stderr, never on stdout**, the property #122 measured byte for byte
        ->and(Artisan::output())->toBeEmpty();
});

it('clears the fleet events of the session it ends, and keeps what the bridge wrote', function (): void {
    // **The shutdown nothing else covers.** Every other test of the record drives `Bridge` and
    // calls the clearing by hand; this drives the command, whose `finally` is the only production
    // caller. Move that call before the loop, drop it, or make it remove the file, and only this
    // test notices.
    bridgeEnrolled();
    bridgeService();

    bridgeSink()->add([
        ['type' => 'directive', 'body' => 'a task this session was told about'],
        ['type' => Bridge::SESSION_ENDED, 'body' => 'why the previous bridge stopped'],
    ]);

    expect(Artisan::call('mcp', ['--service' => MCP_SERVICE, '--project' => 'probe', '--auto-join' => true]))->toBe(0);

    $waiting = bridgeSink()->peek();

    // The directive named work THIS session held, so the next one must not react to it. The
    // bridge record is the one thing in the sink written FOR the session that comes after.
    expect($waiting)->toHaveCount(1)
        ->and($waiting[0]['type'])->toBe('bridge.session-ended');
});

it('leaves the sink alone when nobody joined, because it opened none', function (): void {
    // The control. A bridge nobody asked to join holds no sink, so it has nothing to clear -- and
    // clearing one anyway would throw away a record the PREVIOUS bridge left for this agent.
    bridgeEnrolled();
    bridgeService();

    bridgeSink()->add([['type' => Bridge::SESSION_ENDED, 'body' => 'from the bridge before this one']]);

    expect(Artisan::call('mcp', ['--service' => MCP_SERVICE, '--project' => 'probe']))->toBe(0)
        ->and(bridgeSink()->peek())->toHaveCount(1);
});

/**
 * A fake service whose session start answers with the given capacity fields (#302).
 *
 * @param  array<array-key, mixed>  $answer  What the start response adds.
 */
function bridgeCapacityService(array $answer): void
{
    Http::fake([
        '*/api/sessions/*' => Http::response('', 204),
        '*/api/sessions' => Http::response(array_merge(['session_id' => MCP_SESSION, 'token' => 'rcouncil_2|SESSION-TOKEN', 'abilities' => [], 'expires_in' => 3600], $answer), 201),
        '*/api/agent/session' => Http::response(['fleet_can_direct' => true], 200),
        '*' => Http::response('', 200),
    ]);
}

/**
 * The capacity the one session start sent, or null.
 */
function bridgeStartCapacity(): mixed
{
    $start = Http::recorded(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/api/sessions'))->first();

    return $start === null ? null : ($start[0]->data()['capacity'] ?? null);
}

it('joins with a declared capacity and says what is in effect', function (int $asked, array $answer, string $says): void {
    bridgeEnrolled();
    bridgeCapacityService($answer);

    $output = new TwoStreamOutput;

    Artisan::call('mcp', ['--service' => MCP_SERVICE, '--auto-join' => true, '--capacity' => (string) $asked], $output);

    expect(bridgeStartCapacity())->toBe($asked)
        ->and($output->stderr())->toContain($says)
        ->and($output->stdout())->toBeEmpty();
})->with([
    'as asked' => [3, ['capacity' => 3, 'declared_capacity' => 3], 'It holds up to 3 tasks at once.'],
    'one, as asked' => [1, ['capacity' => 1, 'declared_capacity' => 1], 'It holds up to 1 task at once.'],
    'capped by the seat' => [3, ['capacity' => 2, 'declared_capacity' => 3], 'It declared a capacity of 3; its seat caps it at 2, so it holds up to 2 tasks at once.'],
    // A new seat's cap is 1, so this is the common first answer
    'capped by a new seat' => [3, ['capacity' => 1, 'declared_capacity' => 3], 'its seat caps it at 1, so it holds up to 1 task at once.'],
    'past the fleet limit' => [20, ['capacity' => 16, 'declared_capacity' => 16], 'It declared a capacity of 20; the fleet takes at most 16, so it holds up to 16 tasks at once.'],
    'past both' => [20, ['capacity' => 1, 'declared_capacity' => 16], 'the fleet takes at most 16, and its seat caps it at 1, so it holds up to 1 task at once.'],
    'from a service that predates capacity' => [3, [], 'the fleet reported none'],
    'a whole number of any size' => [1000000, ['capacity' => 16, 'declared_capacity' => 16], 'the fleet takes at most 16'],
]);

it('sends no capacity when none is declared', function (): void {
    bridgeEnrolled();
    bridgeCapacityService([]);

    Artisan::call('mcp', ['--service' => MCP_SERVICE, '--auto-join' => true]);

    expect(bridgeStartCalls())->toBe(1)
        ->and(bridgeStartCapacity())->toBeNull();
});

it('refuses an unusable capacity before any request, and does not join', function (?string $given): void {
    bridgeEnrolled();
    bridgeCapacityService([]);

    $output = new TwoStreamOutput;

    Artisan::call('mcp', ['--service' => MCP_SERVICE, '--auto-join' => true, '--capacity' => $given], $output);

    expect(bridgeStartCalls())->toBe(0)
        ->and($output->stderr())->toContain('--capacity takes a whole number, 1 or more')

        // Not joining at all, rather than a join that failed for some other reason
        ->and($output->stderr())->not->toContain('Could not join the fleet');
})->with(['zero' => ['0'], 'negative' => ['-2'], 'a fraction' => ['1.5'], 'a word' => ['many'], 'bare' => [null]]);

it('says a capacity without --auto-join does nothing, whatever it is', function (?string $given): void {
    bridgeEnrolled();
    bridgeCapacityService([]);

    $output = new TwoStreamOutput;

    Artisan::call('mcp', ['--service' => MCP_SERVICE, '--capacity' => $given], $output);

    expect($output->stderr())->toContain('--capacity applies only with --auto-join')

        // Nothing was going to join automatically, so nothing was skipped
        ->and($output->stderr())->not->toContain('not joining automatically')
        ->and(bridgeStartCalls())->toBe(0);
})->with(['usable' => ['3'], 'unusable' => ['0'], 'bare' => [null]]);
