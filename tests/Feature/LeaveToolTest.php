<?php

declare(strict_types=1);

/**
 * The `leave` tool: taking a session off the fleet without stopping the bridge (#336).
 *
 * A joined bridge adds `leave` to the fleet's tools. Calling it does what a shutdown does -- ends the
 * session, clears the sink's fleet events, and deletes a Cursor join record -- and returns the
 * bridge to offering `join` alone, so the operator can rejoin later in the same process.
 *
 * @command  vendor/bin/pest --compact tests/Feature/LeaveToolTest.php
 */

use App\Support\Bridge;
use App\Support\Credentials\Credential;
use App\Support\FleetFollower;
use App\Support\Joined;
use App\Support\JoinRecord;
use App\Support\PendingEvents;
use App\Support\Session;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

const LEAVE_SERVICE = 'https://leave.example.test';
const LEAVE_PARENT = ['pid' => 4242, 'started' => 'Mon Sep 28 10:00:00 2026'];

/**
 * Fake a service that starts sessions 61, 62, ... and answers the fleet's MCP endpoint.
 */
function leaveService(): void
{
    Http::fake([
        '*/api/sessions/*' => Http::response('', 204),
        '*/api/sessions' => Http::sequence()
            ->push(['session_id' => 61, 'token' => 'rcouncil_2|A', 'expires_in' => 3600, 'feed_cursor' => 1, 'abilities' => []], 201)
            ->push(['session_id' => 62, 'token' => 'rcouncil_2|B', 'expires_in' => 3600, 'feed_cursor' => 1, 'abilities' => []], 201),
        '*/api/agent/session' => Http::response(['fleet_can_direct' => true], 200),
        '*/api/events*' => Http::response(['events' => [], 'cursor' => 1], 200),
        '*/api/mcp' => fn (Request $request) => Http::response((string) json_encode([
            'jsonrpc' => '2.0',
            'id' => data_get(json_decode($request->body(), true), 'id'),
            'result' => data_get(json_decode($request->body(), true), 'method') === 'initialize'
                ? ['protocolVersion' => '2025-11-25', 'capabilities' => ['tools' => new stdClass]]
                : ['tools' => [['name' => 'task_list', 'inputSchema' => ['type' => 'object', 'properties' => new stdClass]]]],
        ]), 200),
        '*' => Http::response('', 200),
    ]);
}

function leaveSink(): PendingEvents
{
    return new PendingEvents(LEAVE_SERVICE, 'cursor');
}

function leaveRecord(): JoinRecord
{
    return new JoinRecord(leaveSink()->joinRecordPath(), LEAVE_PARENT);
}

/**
 * Run one unjoined bridge over the given messages and return what it wrote, decoded.
 *
 * @param  list<string>  $lines  What the harness sends, in order.
 * @param  list<string>  $said  Where its stderr lines are collected.
 * @return list<array<array-key, mixed>>
 */
function leaveRun(array $lines, array &$said = []): array
{
    $join = function (array $arguments): Joined {
        $session = new Session(app(Factory::class), LEAVE_SERVICE, new Credential('rcouncil_1|LEAVE-INSTALLATION'));
        $session->start();

        return new Joined($session, new FleetFollower($session, LEAVE_SERVICE, leaveSink(), 0), 'Joined the fleet.');
    };

    $in = tmpfile();
    fwrite($in, implode("\n", $lines)."\n");
    rewind($in);
    $out = tmpfile();

    new Bridge(null, LEAVE_SERVICE, join: $join, joinRecord: leaveRecord())
        ->run($in, $out, function (string $m) use (&$said): void {
            $said[] = $m;
        });

    rewind($out);

    $written = [];

    foreach (explode("\n", trim((string) stream_get_contents($out))) as $line) {
        $decoded = json_decode($line, true);

        expect($decoded)->toBeArray("stdout carried a line that is not a protocol message: {$line}");

        if (is_array($decoded)) {
            $written[] = $decoded;
        }
    }

    return $written;
}

function leaveCall(int $id, string $tool): string
{
    return (string) json_encode(['jsonrpc' => '2.0', 'id' => $id, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => new stdClass]]);
}

function leaveList(int $id, ?string $cursor = null): string
{
    return (string) json_encode(['jsonrpc' => '2.0', 'id' => $id, 'method' => 'tools/list', 'params' => $cursor === null ? new stdClass : ['cursor' => $cursor]]);
}

/**
 * The names of the tools in the reply to the given request id.
 *
 * @param  list<array<array-key, mixed>>  $written
 * @return list<mixed>
 */
function leaveToolNames(array $written, int $id): array
{
    foreach ($written as $message) {
        if (($message['id'] ?? null) === $id) {
            return array_column((array) data_get($message, 'result.tools', []), 'name');
        }
    }

    return [];
}

/**
 * The reply to the given request id, or null.
 *
 * @param  list<array<array-key, mixed>>  $written
 * @return array<array-key, mixed>|null
 */
function leaveReply(array $written, int $id): ?array
{
    foreach ($written as $message) {
        if (($message['id'] ?? null) === $id) {
            return $message;
        }
    }

    return null;
}

/**
 * How many times each session was ended.
 *
 * @return array<int, int>
 */
function leaveEnds(): array
{
    $ends = [];

    foreach (Http::recorded(fn (Request $request): bool => $request->method() === 'DELETE') as $pair) {
        $id = (int) basename($pair[0]->url());
        $ends[$id] = ($ends[$id] ?? 0) + 1;
    }

    return $ends;
}

beforeEach(function (): void {
    Http::preventStrayRequests();

    $this->state = sys_get_temp_dir().'/rc-leave-'.bin2hex(random_bytes(6));
    putenv('XDG_STATE_HOME='.$this->state);
});

afterEach(function (): void {
    putenv('XDG_STATE_HOME');

    File::deleteDirectory($this->state);
});

it('offers leave beside the fleet tools once joined, and not before', function (): void {
    leaveService();

    $written = leaveRun([leaveList(1), leaveCall(2, 'join'), leaveList(3)]);

    // The control is the first list: an unjoined bridge offers `join` alone
    expect(leaveToolNames($written, 1))->toBe(['join'])
        ->and(leaveToolNames($written, 3))->toBe(['task_list', 'leave']);
});

it('adds leave to the first page only, when the harness pages for itself', function (): void {
    leaveService();

    $written = leaveRun([leaveCall(1, 'join'), leaveList(2, 'page-2')]);

    expect(leaveToolNames($written, 2))->toBe(['task_list']);
});

it('ends the session, clears the fleet events, forgets the join record, and offers join again', function (): void {
    leaveService();

    // What a session leaves in its sink: a fleet event, and one entry of each of the bridge's own kinds
    leaveSink()->add([['id' => 9, 'type' => 'directive', 'body' => 'hold']]);
    leaveSink()->leaveNotice(Bridge::SESSION_ENDED, 'The fleet ended session 60.');
    leaveSink()->leaveNotice(Bridge::CAPACITY_CHANGED, 'capacity moved');

    $written = leaveRun([leaveCall(1, 'join'), leaveCall(2, 'leave'), leaveList(3)]);

    expect(data_get(leaveReply($written, 2), 'result.isError'))->toBeFalse()
        ->and(data_get(leaveReply($written, 2), 'result.content.0.text'))->toBeString()->toContain('Left the fleet: session 61 ended')
        ->and(leaveToolNames($written, 3))->toBe(['join'])
        ->and(array_column($written, 'method'))->toContain('notifications/tools/list_changed')

        // The session ended once, here, and not again by anything after
        ->and(leaveEnds())->toBe([61 => 1])

        // As a shutdown clears it: the fleet event and the capacity entry go, the session-ended record stays
        ->and(array_column(leaveSink()->peek(), 'type'))->toBe([Bridge::SESSION_ENDED])
        ->and(leaveRecord()->recall())->toBeNull();
});

it('recorded the join it then forgot', function (): void {
    // The control for the record half of the test above: without `leave`, the join does leave one
    leaveService();

    leaveRun([leaveCall(1, 'join')]);

    expect(leaveRecord()->recall()['parent'] ?? null)->toBe(LEAVE_PARENT);
});

it('rejoins in the same process after leaving, as a new session, and records the join again', function (): void {
    leaveService();

    $written = leaveRun([leaveCall(1, 'join'), leaveCall(2, 'leave'), leaveCall(3, 'join'), leaveList(4)]);

    expect(data_get(leaveReply($written, 3), 'result.isError'))->toBeFalse()
        ->and(leaveToolNames($written, 4))->toBe(['task_list', 'leave'])
        ->and(leaveEnds())->toBe([61 => 1])

        // A later reload under the same application rejoins again, from this record
        ->and(leaveRecord()->recall()['parent'] ?? null)->toBe(LEAVE_PARENT);
});

it('answers leave on an unjoined bridge as a tool it does not have', function (): void {
    leaveService();

    $written = leaveRun([leaveCall(1, 'leave')]);

    expect(data_get(leaveReply($written, 1), 'error.code'))->toBe(-32002)
        ->and(leaveEnds())->toBeEmpty();
});

it('leaves nothing for the shutdown to end twice', function (): void {
    leaveService();

    $join = function (array $arguments): Joined {
        $session = new Session(app(Factory::class), LEAVE_SERVICE, new Credential('rcouncil_1|LEAVE-INSTALLATION'));
        $session->start();

        return new Joined($session, new FleetFollower($session, LEAVE_SERVICE, leaveSink(), 0), 'Joined the fleet.');
    };

    $in = tmpfile();
    fwrite($in, leaveCall(1, 'join')."\n".leaveCall(2, 'leave')."\n");
    rewind($in);

    $bridge = new Bridge(null, LEAVE_SERVICE, join: $join);
    $bridge->run($in, tmpfile(), fn (string $m): null => null);

    // What `mcp` ends on its way out: nothing, since the session already left
    expect($bridge->session())->toBeNull();
});

it('starts a rejoined session from its own capacity, not the one that left', function (): void {
    Http::fake([
        '*/api/sessions/*' => Http::response('', 204),
        '*/api/sessions' => Http::sequence()
            ->push(['session_id' => 61, 'token' => 'rcouncil_2|A', 'expires_in' => 3600, 'feed_cursor' => 1, 'capacity' => 1], 201)
            ->push(['session_id' => 62, 'token' => 'rcouncil_2|B', 'expires_in' => 3600, 'feed_cursor' => 1, 'capacity' => 3], 201),
        '*/api/agent/session' => Http::sequence()->push(['capacity' => 1], 200)->push(['capacity' => 3], 200),
        '*/api/events*' => Http::response(['events' => [], 'cursor' => 1], 200),
        '*' => Http::response('', 200),
    ]);

    $join = function (array $arguments): Joined {
        $session = new Session(app(Factory::class), LEAVE_SERVICE, new Credential('rcouncil_1|LEAVE-INSTALLATION'));
        $session->start();

        return new Joined($session, new FleetFollower($session, LEAVE_SERVICE, leaveSink(), 0), 'Joined the fleet.');
    };

    // Reading the capacity on every pass, so each run below ends with one read
    $bridge = new Bridge(null, LEAVE_SERVICE, join: $join, capacitySeconds: 0);
    $said = [];

    foreach ([[leaveCall(1, 'join')], [leaveCall(2, 'leave'), leaveCall(3, 'join')]] as $lines) {
        $in = tmpfile();
        fwrite($in, implode("\n", $lines)."\n");
        rewind($in);

        $bridge->run($in, tmpfile(), function (string $m) use (&$said): void {
            $said[] = $m;
        });
    }

    // Session 62 started at 3 and reads 3: no change, although session 61's baseline was 1
    expect(implode("\n", $said))->not->toContain('capacity changed')
        ->and(Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/api/agent/session'))->count())->toBeGreaterThanOrEqual(2);
});
