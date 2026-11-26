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
use App\Support\StdinReader;
use App\Support\WakeAdapters;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Assert;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Process\Process;
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
    array_map(unlink(...), glob($this->stateDirectory.'/robot-council/*.jsonl') ?: []);
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

    // **Settled rather than snapshotted.** Process teardown is not instantaneous, so a child of an
    // earlier command in this process -- the order is random -- can still be exiting. Measured: one
    // run in two failed on an instant read. A short bound still fails outright on a genuine leak,
    // which does not go away by waiting. Counted under this process only (#366).
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
 * How many `mcp:reader` processes are running as direct children of a process (#366).
 *
 * **Children of one process, not every reader on the machine.** The count used to be machine-wide,
 * so any other agent session's bridge failed the test that asks whether *this* command left one
 * behind: 19 were counted against an expected 0 with 38 robot-council processes running. The bridge
 * starts its reader with `proc_open()` and an argument array, so the reader is a direct child of the
 * process running the test, with no shell between them (`Support\StdinReader`).
 *
 * @param  int|null  $parent  The parent process id to count under; this process by default.
 */
function readersRunning(?int $parent = null): int
{
    $parent ??= (int) getmypid();

    if (PHP_OS_FAMILY === 'Linux') {
        $running = 0;

        foreach (glob('/proc/[0-9]*', GLOB_ONLYDIR) ?: [] as $directory) {
            $command = @file_get_contents($directory.'/cmdline');
            $status = @file_get_contents($directory.'/stat');

            // The parent is the second field after the command name, which is parenthesized and may
            // itself contain spaces or a parenthesis, so it is read after the last one.
            if (\is_string($command) && str_contains($command, 'mcp:reader') && \is_string($status) && preg_match('/^\d+ \(.*\)\s+\S+\s+(\d+)/s', $status, $fields) === 1 && (int) $fields[1] === $parent) {
                $running++;
            }
        }

        return $running;
    }

    $script = sys_get_temp_dir().'/rc-mcp-'.bin2hex(random_bytes(6)).'.ps1';

    file_put_contents(
        $script,
        sprintf('(Get-CimInstance Win32_Process | Where-Object { $_.ParentProcessId -eq %d -and $_.CommandLine -like "*mcp:reader*" } | Measure-Object).Count', $parent)
    );

    $output = shell_exec(sprintf(
        'powershell -NoProfile -NonInteractive -ExecutionPolicy Bypass -File %s',
        escapeshellarg($script)
    ));

    @unlink($script);

    return (int) trim((string) $output);
}

/**
 * A process that stays alive with `mcp:reader` on its command line, and optionally one more below it.
 *
 * Stands in for a reader, so the counter can be shown to see a leaked child and to ignore one it
 * does not own, without depending on what else is running on the machine.
 *
 * @param  bool  $withGrandchild  Whether it starts a second marked process of its own.
 */
function markedProcess(bool $withGrandchild = false): Process
{
    $code = $withGrandchild
        ? '$p = proc_open([PHP_BINARY, "-r", "usleep(20000000);", "mcp:reader"], [], $pipes); usleep(20000000);'
        : 'usleep(20000000);';

    $process = new Process([PHP_BINARY, '-r', $code, 'mcp:reader'], timeout: 40);
    $process->start();

    return $process;
}

it('counts a reader this process owns, and not one below another process or on the machine at large', function (): void {
    $child = markedProcess(withGrandchild: true);

    try {
        // Until the grandchild exists: a count of 1 under the child is only meaningful once there are
        // two marked processes below this one, one of them a grandchild. Polled, because a cold PHP
        // start on a loaded runner can take longer than any fixed wait.
        $below = 0;

        for ($attempt = 0; $attempt < 100 && $below < 1; $attempt++) {
            usleep(100_000);

            $below = readersRunning((int) $child->getPid());
        }

        expect($child->isRunning())->toBeTrue()
            ->and(readersRunning())->toBe(1)
            ->and(readersRunning((int) $child->getPid()))->toBe(1);
    } finally {
        $child->stop(0);
    }

    // Once the child is stopped there is nothing left of this process's to count.
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

it('describes --capacity as taking the seat setting when omitted (#361)', function (): void {
    $command = Artisan::all()['mcp'] ?? null;

    Assert::assertInstanceOf(Command::class, $command);

    expect($command->getDefinition()->getOption('capacity')->getDescription())->toContain('Tasks at once setting of its seat')
        ->toContain('1 keeps it at one');
});

/**
 * The lines the command left in this test's record of why bridges stop (#396).
 *
 * @return list<array<array-key, mixed>>
 */
function bridgeStops(): array
{
    $path = getenv('XDG_STATE_HOME').'/robot-council/stops.jsonl';

    if (! is_file($path)) {
        return [];
    }

    $stops = [];

    foreach (explode("\n", (string) file_get_contents($path)) as $line) {
        $decoded = $line === '' ? null : json_decode($line, true, 512, JSON_THROW_ON_ERROR);

        if (\is_array($decoded)) {
            $stops[] = $decoded;
        }
    }

    return $stops;
}

it('leaves one line saying stdin closed when the harness goes away', function (): void {
    bridgeEnrolled();
    bridgeService();

    $exit = Artisan::call('mcp', ['--service' => MCP_SERVICE, '--auto-join' => true]);

    $stops = bridgeStops();

    // Exactly one: a stop that writes two lines would double every count made from the file
    expect($exit)->toBe(0)
        ->and($stops)->toHaveCount(1)
        ->and($stops[0]['reason'])->toBe('stdin-closed')
        ->and($stops[0]['session'])->toBe(MCP_SESSION)
        ->and($stops[0]['pid'])->toBe(getmypid())
        // The reader saw the harness close stdin and went with exit 0; read before it was stopped, so not `none`
        ->and($stops[0]['reader'])->toMatch('/^(exited-0|running)$/')
        ->and($stops[0]['version'])->toBeString()->not->toBe('');
});

it('writes the line before it ends the session', function (): void {
    bridgeEnrolled();

    // The record is read at the instant the session-end request arrives: a line written after it
    // would be lost to any failure in the steps in between
    $seenAtEnd = null;

    Http::fake([
        '*/api/sessions/*' => function (Request $request) use (&$seenAtEnd) {
            if ($request->method() === 'DELETE') {
                $seenAtEnd = bridgeStops();
            }

            return Http::response('', 204);
        },
        '*/api/sessions' => Http::response([
            'session_id' => MCP_SESSION,
            'token' => 'rcouncil_2|SESSION-TOKEN',
            'abilities' => ['tasks:create'],
            'expires_in' => 3600,
        ], 201),
        '*' => Http::response('', 200),
    ]);

    Artisan::call('mcp', ['--service' => MCP_SERVICE, '--auto-join' => true]);

    expect($seenAtEnd)->toBeArray()->toHaveCount(1)
        ->and(($seenAtEnd ?? [])[0]['reason'] ?? null)->toBe('stdin-closed');
});

it('still ends cleanly, and says so on stderr, when the record cannot be written', function (): void {
    bridgeEnrolled();
    bridgeService();

    // A file where the state directory's `robot-council` folder has to be
    mkdir($this->stateDirectory);
    file_put_contents($this->stateDirectory.'/robot-council', 'not a directory');

    $exit = Artisan::call('mcp', ['--service' => MCP_SERVICE, '--auto-join' => true]);

    expect($exit)->toBe(0)
        // The session still ended, once, and the failure did not stop the steps after the record
        ->and(bridgeEndCalls())->toBe(1);

    @unlink($this->stateDirectory.'/robot-council');
});

it('leaves one line, and says nothing on stdout, when setup throws after the reader started (#400)', function (): void {
    putenv('ROBOT_COUNCIL_HARNESS=amp');

    // A factory that throws something `WakeAdapters::for()` does not catch, so it escapes the setup
    // between the reader and the loop
    app()->instance(WakeAdapters::class, new WakeAdapters([
        'amp' => fn (string $target): never => throw new LogicException('boom rcouncil_2|PLANTED-SECRET'),
    ]));

    // **A reader whose stdin stays open (#402).** Stdin is already at end of file under a test runner,
    // so the command's own reader exits by itself and a missing `stop()` could never show. This one
    // reads a pipe that a sleeping process holds, so only `abandon()` stopping it can end it.
    $holder = proc_open([PHP_BINARY, '-r', 'usleep(60000000);'], [1 => ['pipe', 'w']], $pipes);

    expect($holder)->toBeResource();

    $countable = in_array(PHP_OS_FAMILY, ['Windows', 'Linux'], true);
    $aliveBefore = null;

    app()->bind(StdinReader::class, function () use ($pipes, &$aliveBefore): StdinReader {
        $reader = new StdinReader($pipes[1]);

        // The control: the child is running when setup begins, so its later absence is an absence
        $aliveBefore = readersRunning();

        return $reader;
    });

    $output = new TwoStreamOutput;

    try {
        $exit = Artisan::call('mcp', ['--service' => MCP_SERVICE, '--wake-target' => 'thread-9'], $output);

        // Settled: a reader still exiting is not a leak, one that stays is
        $running = readersRunning();

        for ($attempt = 0; $attempt < 30 && $running > 0 && $countable; $attempt++) {
            usleep(100_000);

            $running = readersRunning();
        }
    } finally {
        // Ends whatever a failing run left behind, so a red test does not leak a child
        if (\is_resource($holder)) {
            proc_terminate($holder);
            proc_close($holder);
        }
    }

    $stops = bridgeStops();
    $raw = (string) file_get_contents(getenv('XDG_STATE_HOME').'/robot-council/stops.jsonl');

    expect($exit)->toBe(Command::FAILURE)
        ->and($stops)->toHaveCount(1)
        ->and($stops[0]['reason'])->toBe('unhandled-exception')
        ->and($stops[0]['exception'])->toBe('LogicException')
        // Read before the reader was stopped, and the child is still running: its stdin never closed
        ->and($stops[0]['reader'])->toBe('running')
        ->and($stops[0])->not->toHaveKey('session')
        // Nothing of the message, on the line or on the protocol stream
        ->and($raw)->not->toContain('PLANTED-SECRET')
        ->and($output->stdout())->toBeEmpty()
        ->and($output->stderr())->toContain('The bridge stopped unexpectedly.')
        // The seat's record of its sink key went with it (cli#346)
        ->and(glob(getenv('XDG_STATE_HOME').'/robot-council/pending/*.key') ?: [])->toBeEmpty()
        // Counted only where counting is implemented; elsewhere `readersRunning()` has no basis
        ->and($countable ? $aliveBefore : 1)->toBe(1)
        ->and($countable ? $running : 0)->toBe(0);
});

it('leaves one start-failed line, and nothing else on it, when there is no service URL (#403)', function (): void {
    putenv('ROBOT_COUNCIL_SERVICE');

    $output = new TwoStreamOutput;

    $exit = Artisan::call('mcp', [], $output);

    $stops = bridgeStops();

    expect($exit)->toBe(Command::FAILURE)
        ->and($stops)->toHaveCount(1)
        ->and($stops[0]['reason'])->toBe('start-failed')
        // No reader and no session exist at this return, and no throwable caused it
        ->and($stops[0])->not->toHaveKey('reader')
        ->and($stops[0])->not->toHaveKey('session')
        ->and($stops[0])->not->toHaveKey('exception')
        ->and($stops[0]['pid'])->toBe(getmypid())
        ->and($output->stdout())->toBeEmpty()
        ->and($output->stderr())->toContain('Pass --service');
});

it('leaves one start-failed line naming the class, and none of the message, when the reader will not start (#403)', function (): void {
    // A reader that cannot start, carrying something that must never reach the line
    app()->bind(StdinReader::class, function (): never {
        throw new RuntimeException('Could not listen: rcouncil_2|PLANTED-SECRET https://bridge.example.test/?token=PLANTED-SECRET');
    });

    $output = new TwoStreamOutput;

    $exit = Artisan::call('mcp', ['--service' => MCP_SERVICE], $output);

    $stops = bridgeStops();
    $raw = (string) file_get_contents(getenv('XDG_STATE_HOME').'/robot-council/stops.jsonl');

    expect($exit)->toBe(Command::FAILURE)
        ->and($stops)->toHaveCount(1)
        ->and($stops[0]['reason'])->toBe('start-failed')
        ->and($stops[0]['exception'])->toBe('RuntimeException')
        ->and($stops[0])->not->toHaveKey('reader')
        ->and($stops[0])->not->toHaveKey('session')
        ->and($raw)->not->toContain('PLANTED-SECRET')
        ->and($raw)->not->toContain('bridge.example.test')
        ->and($output->stdout())->toBeEmpty();
});
