<?php

declare(strict_types=1);

/**
 * A restarted Cursor bridge rejoining, and only under the application that joined (#333).
 *
 * Cursor restarts its MCP servers by itself, and every stop ends the fleet session. So a Cursor
 * join is recorded with the parent process it ran under, and a bridge that starts under the same
 * parent (pid AND start time) rejoins with what was recorded. Under any other parent it tells the
 * agent, and joins nothing.
 *
 * **The command runs in process for the service half**, with a fixed parent bound in the container,
 * and **as a real child process for the identity half**, where two processes sharing a parent is
 * the claim and nothing can stand in for it.
 *
 * @command  vendor/bin/pest --compact tests/Feature/CursorRejoinTest.php
 */

use App\Commands\McpCommand;
use App\Support\Bridge;
use App\Support\Credentials\Credential;
use App\Support\Credentials\Credentials;
use App\Support\JoinRecord;
use App\Support\ParentProcess;
use App\Support\PendingEvents;
use App\Support\Session;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;
use Tests\Fixtures\RecordingStore;
use Tests\Fixtures\TwoStreamOutput;

const REJOIN_SERVICE = 'https://rejoin.example.test';
const REJOIN_SESSION = 51;
const REJOIN_PARENT = ['pid' => 4242, 'started' => 'Mon Sep 28 10:00:00 2026'];
const REJOIN_INITIALIZE = '{"jsonrpc":"2.0","id":0,"method":"initialize","params":{"protocolVersion":"2025-11-25"}}';

/**
 * Where the command keeps the record for a harness, keyed as it keys the sink.
 */
function rejoinRecordPath(string $harness = 'cursor'): string
{
    return new PendingEvents(REJOIN_SERVICE, $harness)->joinRecordPath();
}

/**
 * Leave a record as an earlier bridge would have, under the given parent.
 *
 * @param  array{pid: int, started: string}|null  $parent
 */
function rejoinSeed(?array $parent = REJOIN_PARENT, string $harness = 'cursor'): void
{
    new JoinRecord(rejoinRecordPath($harness), $parent)->remember([
        'role' => null,
        'repository' => 'org/repo',
        'work_location' => 'repo-c',
        'capacity' => 2,
    ]);
}

/**
 * Enroll this harness, and fake a service that starts, describes and ends a session.
 */
function rejoinService(string $harness = 'cursor'): void
{
    $store = new RecordingStore;
    $store->put(REJOIN_SERVICE.'|'.$harness, new Credential('rcouncil_1|REJOIN-INSTALLATION'));

    app()->instance(Credentials::class, new Credentials([$store]));

    Http::fake([
        '*/api/sessions/*' => Http::response('', 204),
        '*/api/sessions' => Http::response(['session_id' => REJOIN_SESSION, 'token' => 'rcouncil_2|T', 'expires_in' => 3600, 'abilities' => []], 201),
        '*/api/agent/session' => Http::response(['fleet_can_direct' => true], 200),
        '*' => Http::response('', 200),
    ]);
}

/**
 * Run `mcp` under the given harness and parent, and return what it said on stderr.
 *
 * @param  array{pid: int, started: string}|null  $parent
 * @param  array<string, mixed>  $options
 */
function rejoinRun(string $harness = 'cursor', ?array $parent = REJOIN_PARENT, array $options = []): string
{
    putenv('ROBOT_COUNCIL_HARNESS='.$harness);
    app()->instance(ParentProcess::class, new ParentProcess($parent));

    $output = new TwoStreamOutput;

    expect(Artisan::call('mcp', ['--service' => REJOIN_SERVICE, ...$options], $output))->toBe(0);

    return $output->stderr();
}

/**
 * The session starts the command sent, each as its body.
 *
 * @return list<array<array-key, mixed>>
 */
function rejoinStarts(): array
{
    $starts = [];

    foreach (Http::recorded(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/api/sessions')) as $pair) {
        $starts[] = $pair[0]->data();
    }

    return $starts;
}

/**
 * A value that must be a string, or the test fails saying what it was.
 */
function rejoinText(mixed $value): string
{
    if (! \is_string($value)) {
        throw new RuntimeException(sprintf('Expected a string, got %s.', get_debug_type($value)));
    }

    return $value;
}

/**
 * This process's pid, which a test process always has.
 */
function rejoinPid(): int
{
    $pid = getmypid();

    if ($pid === false) {
        throw new RuntimeException('This process has no pid to read.');
    }

    return $pid;
}

function rejoinEnds(): int
{
    return Http::recorded(fn (Request $request): bool => $request->method() === 'DELETE' && str_contains($request->url(), '/api/sessions/'.REJOIN_SESSION))->count();
}

beforeEach(function (): void {
    Http::preventStrayRequests();

    $this->state = sys_get_temp_dir().'/rc-rejoin-'.bin2hex(random_bytes(6));
    putenv('XDG_STATE_HOME='.$this->state);
    putenv('CLAUDE_CONFIG_DIR='.$this->state.'/claude');
});

afterEach(function (): void {
    putenv('XDG_STATE_HOME');
    putenv('CLAUDE_CONFIG_DIR');
    putenv('ROBOT_COUNCIL_HARNESS');

    File::deleteDirectory($this->state);
});

it('records a Cursor join with what it joined with and the parent it ran under', function (): void {
    rejoinService();

    expect(new JoinRecord(rejoinRecordPath(), REJOIN_PARENT)->recall())->toBeNull();

    rejoinRun(options: ['--auto-join' => true, '--capacity' => '3']);

    $recalled = new JoinRecord(rejoinRecordPath(), REJOIN_PARENT)->recall();

    expect($recalled['parent'] ?? null)->toBe(REJOIN_PARENT)
        ->and($recalled['arguments'] ?? null)->toBe(['role' => null, 'repository' => null, 'work_location' => null, 'capacity' => 3])
        ->and($recalled['joined_at'] ?? null)->toBeInt();
});

it('rejoins with the recorded values under the same parent, and says so', function (): void {
    rejoinService();
    rejoinSeed();

    $said = rejoinRun();

    $starts = rejoinStarts();

    expect($starts)->toHaveCount(1)
        ->and($starts[0]['repository'] ?? null)->toBe('org/repo')
        ->and($starts[0]['work_location'] ?? null)->toBe('repo-c')
        ->and($starts[0]['capacity'] ?? null)->toBe(2)
        ->and($said)->toContain('robot-council: rejoining the fleet: this Cursor application joined before its bridge was restarted.');
});

it('keeps the record through a harness stop, which ends the session it rejoined', function (): void {
    rejoinService();
    rejoinSeed();

    rejoinRun();

    // The control: the stop did end the session, so a record surviving it is a choice, not a stop that never ran
    expect(rejoinEnds())->toBe(1)
        ->and(new JoinRecord(rejoinRecordPath(), REJOIN_PARENT)->recall()['parent'] ?? null)->toBe(REJOIN_PARENT);
});

it('does not rejoin under a different parent', function (int $pid, string $started): void {
    rejoinService();
    rejoinSeed();

    $said = rejoinRun(parent: ['pid' => $pid, 'started' => $started]);

    expect(rejoinStarts())->toBeEmpty()
        ->and($said)->not->toContain('rejoining');
})->with([
    'another pid' => [4243, REJOIN_PARENT['started']],
    'another start time' => [4242, 'Mon Sep 28 10:00:01 2026'],
]);

it('does not rejoin when its parent cannot be read, or the record could not name one', function (bool $readThen, bool $readNow): void {
    rejoinService();
    rejoinSeed($readThen ? REJOIN_PARENT : null);

    rejoinRun(parent: $readNow ? REJOIN_PARENT : null);

    expect(rejoinStarts())->toBeEmpty();
})->with([
    'unreadable now' => [true, false],
    'unreadable then' => [false, true],
]);

it('starts unjoined and says nothing when there is no record', function (): void {
    rejoinService();

    $said = rejoinRun();

    expect(rejoinStarts())->toBeEmpty()
        ->and($said)->not->toContain('rejoin');
});

it('changes nothing for another harness: no rejoin, and no record written', function (string $harness): void {
    rejoinService($harness);

    // A record under this harness's own key, with a matching parent, which is everything a Cursor bridge would rejoin from
    rejoinSeed(harness: $harness);

    rejoinRun($harness);

    expect(rejoinStarts())->toBeEmpty();

    File::delete(rejoinRecordPath($harness));

    rejoinRun($harness, options: ['--auto-join' => true]);

    // The control is the join itself: it happened, and still left no record
    expect(rejoinStarts())->toHaveCount(1)
        ->and(rejoinRecordPath($harness))->not->toBeFile();
})->with(['claude', 'codex']);

it('says at connect what happened, in the instructions the agent reads', function (?string $notice): void {
    $bridge = new Bridge(null, REJOIN_SERVICE, join: fn (array $arguments) => throw new RuntimeException('not used'));

    if ($notice !== null) {
        $bridge->tellAtConnect($notice);
    }

    $in = tmpfile();
    fwrite($in, REJOIN_INITIALIZE."\n");
    rewind($in);
    $out = tmpfile();

    $bridge->run($in, $out, fn (string $m): null => null);

    rewind($out);
    $first = strtok((string) stream_get_contents($out), "\n");
    $instructions = rejoinText(data_get(json_decode($first === false ? '' : $first, true), 'result.instructions'));

    $notice === null
        ? expect($instructions)->not->toContain('Cursor')
        : expect($instructions)->toContain($notice);
})->with([
    'rejoined' => [McpCommand::REJOINED_NOTICE],
    'nothing to say' => [null],
]);

it('forgets the record when the fleet ends the session, and keeps it otherwise', function (int $heartbeat, bool $kept): void {
    Http::fake([
        '*/api/sessions/'.REJOIN_SESSION.'/renew' => Http::response(null, 409),
        '*/api/sessions' => Http::response(['session_id' => REJOIN_SESSION, 'token' => 'rcouncil_2|T', 'expires_in' => 3600, 'abilities' => []], 201),
        '*/api/agent/heartbeat' => Http::response(null, $heartbeat),
        '*/api/agent/session' => Http::response(null, $heartbeat),
        '*/api/agent/watcher' => Http::response(null, $heartbeat === 200 ? 204 : $heartbeat),
        '*' => Http::response('', 200),
    ]);

    rejoinSeed();

    $session = new Session(app(Factory::class), REJOIN_SERVICE, new Credential('rcouncil_1|REJOIN-INSTALLATION'));
    $session->start();

    $idle = tmpfile();
    fwrite($idle, "\n");
    rewind($idle);

    new Bridge($session, REJOIN_SERVICE, 0, joinRecord: new JoinRecord(rejoinRecordPath(), REJOIN_PARENT))
        ->run($idle, tmpfile(), fn (string $m): null => null);

    expect(is_file(rejoinRecordPath()))->toBe($kept);
})->with([
    // 401 on the heartbeat, then 409 on the renewal: core's answer for a session it has ended
    'the fleet ended it' => [401, false],
    'the heartbeat was accepted' => [200, true],
]);

/**
 * A real PHP process started by this one, printing what it reads as its parent's identity.
 */
/**
 * @return array{pid: int, started: string}
 */
function rejoinChildIdentity(): array
{
    $child = new Process([PHP_BINARY, '-r', 'require $argv[1]; echo json_encode((new App\Support\ParentProcess)->identity());', base_path('vendor/autoload.php')]);
    $child->mustRun();

    $read = json_decode($child->getOutput(), true);

    if (! \is_array($read) || ! \is_int($read['pid'] ?? null) || ! \is_string($read['started'] ?? null)) {
        throw new RuntimeException('The child read no identity for its parent: '.$child->getOutput());
    }

    return ['pid' => $read['pid'], 'started' => $read['started']];
}

it('reads a real parent by pid and start time, the same way from two of its children', function (): void {
    $first = rejoinChildIdentity();
    $second = rejoinChildIdentity();

    // Two children of this process: the same parent, read twice, with nothing shared between the reads
    expect($first['pid'])->toBe(rejoinPid())
        ->and($first['started'])->not->toBeEmpty()
        ->and($second)->toBe($first);

    // The control: the same pid with any other start time is another process
    expect(new JoinRecord('/unused', $first)->sameParent($first))->toBeTrue()
        ->and(new JoinRecord('/unused', $first)->sameParent(['pid' => $first['pid'], 'started' => $first['started'].'x']))->toBeFalse();
})->skipOnWindows();

/**
 * Start a real `mcp` bridge under this process as a Cursor bridge, send it `initialize`, and return
 * its stderr and the instructions it answered with.
 *
 * @return array{0: string, 1: string}
 */
function rejoinRealBridge(string $state): array
{
    $bridge = new Process(
        [PHP_BINARY, base_path('robot-council'), 'mcp', '--service='.REJOIN_SERVICE],
        $state,
        ['XDG_STATE_HOME' => $state, 'ROBOT_COUNCIL_HARNESS' => 'cursor', 'ROBOT_COUNCIL_SERVICE' => false, 'CLAUDE_CONFIG_DIR' => $state.'/claude'],
        REJOIN_INITIALIZE."\n",
        60,
    );
    $bridge->run();

    $first = strtok($bridge->getOutput(), "\n");

    return [$bridge->getErrorOutput(), rejoinText(data_get(json_decode($first === false ? '' : $first, true), 'result.instructions'))];
}

it('rejoins under the process that started it, end to end, and not under a stranger', function (): void {
    File::ensureDirectoryExists($this->state);

    // Recorded under this test process, which is the parent the bridge started below has
    rejoinSeed(rejoinChildIdentity());

    [$said, $instructions] = rejoinRealBridge($this->state);

    // It tried: the parent matched across two real processes. The fake service is not enrolled on
    // this machine, so the join itself fails, and the agent is told it did not rejoin
    expect($said)->toContain('rejoining the fleet')
        ->and($instructions)->toContain('this bridge has not rejoined');

    // The control: the same record under a parent that differs only in its start time
    rejoinSeed(['pid' => rejoinPid(), 'started' => 'not when this process started']);

    [$said, $instructions] = rejoinRealBridge($this->state);

    expect($said)->not->toContain('rejoining')
        ->and($instructions)->toContain('A Cursor bridge joined the Robot Council fleet from this machine less than a minute ago');
})->skipOnWindows();
