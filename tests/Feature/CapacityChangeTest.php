<?php

declare(strict_types=1);

/**
 * Telling the agent when its capacity moves after the join (#324).
 *
 * A seat's cap can be raised live, and the join result says the capacity once. The bridge reads
 * `GET agent/session` on its own schedule and leaves a `bridge.` entry in the sink when the value
 * moves from what the agent was last told.
 *
 * **Several runs of one bridge rather than one run of several lines**, for the reason `BridgeTest`
 * gives: a run reads its whole input in one chunk and so makes one periodic pass, and the bridge's
 * own state carries across runs.
 *
 * @command  vendor/bin/pest --compact tests/Feature/CapacityChangeTest.php
 */
use App\Support\Bridge;
use App\Support\Credentials\Credential;
use App\Support\FleetFollower;
use App\Support\PendingEvents;
use App\Support\Session;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

const CAPACITY_CHANGE_SERVICE = 'https://fleet.example.test';
const CAPACITY_CHANGE_INSTALLATION = 'rcouncil_1|CAPACITY-INSTALLATION-0123456789';
const CAPACITY_CHANGE_INITIALIZE = '{"jsonrpc":"2.0","id":0,"method":"initialize","params":{"protocolVersion":"2025-11-25"}}';
const CAPACITY_CHANGE_INITIALIZED = '{"jsonrpc":"2.0","method":"notifications/initialized"}';

/**
 * Fake the service, with the session starting at one capacity and `agent/session` answering others.
 *
 * @param  int|null  $atStart  The capacity the session start reports, or null for a service that sends none.
 * @param  list<mixed>  $live  What each read of `agent/session` answers, in order: an int is a
 *                             capacity, null a body without one, and a Response is sent as it is.
 */
function capacityService(?int $atStart, array $live): void
{
    $sequence = Http::sequence();

    foreach ($live as $answer) {
        $answer instanceof Response || $answer instanceof PromiseInterface
            ? $sequence->pushResponse($answer)
            : $sequence->push(array_filter(['session_id' => 7, 'fleet_can_direct' => true, 'capacity' => $answer], fn (mixed $v): bool => $v !== null), 200);
    }

    Http::fake([
        '*/api/sessions' => Http::response(array_filter(['session_id' => 7, 'token' => 'rcouncil_2|T', 'expires_in' => 3600, 'feed_cursor' => 1, 'capacity' => $atStart], fn (mixed $v): bool => $v !== null), 201),
        '*/api/agent/session' => $sequence,
        '*/api/agent/heartbeat' => Http::response('', 200),
        '*/api/agent/watcher' => Http::response('', 204),
        '*/api/events*' => Http::response(['events' => [], 'cursor' => 1], 200),
        '*/api/mcp' => function (Request $request) {
            $message = json_decode($request->body(), true);
            $id = \is_array($message) ? json_encode($message['id'] ?? null) : 'null';

            return match (\is_array($message) ? ($message['method'] ?? null) : null) {
                'initialize' => Http::response('{"jsonrpc":"2.0","id":'.$id.',"result":{"protocolVersion":"2025-11-25","capabilities":{"tools":{}},"serverInfo":{"name":"robot-council","version":"1.0.0"}}}', 200),
                default => Http::response('', 202),
            };
        },
    ]);
}

/**
 * A joined bridge that reads the capacity on every pass, and the sink it writes.
 *
 * @return array{0: Bridge, 1: PendingEvents}
 */
function capacityBridge(int $capacitySeconds = 0, bool $channel = false): array
{
    $session = new Session(app(Factory::class), CAPACITY_CHANGE_SERVICE, new Credential(CAPACITY_CHANGE_INSTALLATION));
    $session->start();

    $pending = new PendingEvents(CAPACITY_CHANGE_SERVICE, 'claude', 'capacity-probe');

    return [
        new Bridge($session, CAPACITY_CHANGE_SERVICE, follower: new FleetFollower($session, CAPACITY_CHANGE_SERVICE, $pending, 0), channel: $channel, capacitySeconds: $capacitySeconds),
        $pending,
    ];
}

/**
 * Run the bridge once over the given lines.
 *
 * @param  list<string>  $lines  What the harness sends.
 * @param  list<string>  $said  Where its stderr lines are collected.
 * @return list<array<array-key, mixed>> What it wrote to stdout, decoded.
 */
function capacityRun(Bridge $bridge, array &$said, array $lines = ['{"jsonrpc":"2.0","method":"notifications/ping"}']): array
{
    $in = tmpfile();
    fwrite($in, implode("\n", $lines)."\n");
    rewind($in);

    $out = tmpfile();

    $bridge->run($in, $out, function (string $m) use (&$said): void {
        $said[] = $m;
    });

    rewind($out);

    return array_values(array_filter(array_map(
        fn (string $line): mixed => json_decode($line, true),
        explode("\n", trim((string) stream_get_contents($out)))
    ), \is_array(...)));
}

/**
 * The capacity entries waiting in the sink, as the agent would read them.
 *
 * @return list<string> Their bodies.
 */
function capacityEntries(PendingEvents $pending): array
{
    $bodies = [];

    foreach ($pending->peek() as $event) {
        if (($event['type'] ?? null) === Bridge::CAPACITY_CHANGED) {
            $body = $event['body'] ?? null;

            if (! \is_string($body)) {
                throw new RuntimeException(sprintf('A capacity entry carried a %s body, not a string.', get_debug_type($body)));
            }

            $bodies[] = $body;
        }
    }

    return $bodies;
}

/**
 * How many times the bridge read `agent/session`.
 */
function capacityReads(): int
{
    return Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/api/agent/session'))->count();
}

beforeEach(function (): void {
    Http::preventStrayRequests();

    $this->state = sys_get_temp_dir().'/rc-capacity-'.bin2hex(random_bytes(6));
    putenv('XDG_STATE_HOME='.$this->state);
});

afterEach(function (): void {
    putenv('XDG_STATE_HOME');

    File::deleteDirectory($this->state);
});

it('tells the agent when its seat is raised after the join', function (): void {
    capacityService(atStart: 1, live: [3]);
    [$bridge, $pending] = capacityBridge();
    $said = [];

    capacityRun($bridge, $said);

    $sentence = "This session's capacity changed from 1 to 3, so it may now hold up to 3 tasks at once.";

    expect(capacityEntries($pending))->toBe([$sentence])
        ->and($said)->toContain($sentence);
});

it('tells the agent when its seat is lowered after the join', function (): void {
    capacityService(atStart: 3, live: [1]);
    [$bridge, $pending] = capacityBridge();
    $said = [];

    capacityRun($bridge, $said);

    expect(capacityEntries($pending))->toBe(["This session's capacity changed from 3 to 1, so it may now hold up to 1 task at once."]);
});

it('says nothing while the capacity is unchanged, although it asked', function (): void {
    capacityService(atStart: 2, live: [2, 2]);
    [$bridge, $pending] = capacityBridge();
    $said = [];

    capacityRun($bridge, $said);
    capacityRun($bridge, $said);

    // The control: it did ask, twice, so the silence is an answer and not a read that never happened
    expect(capacityReads())->toBe(2)
        ->and(capacityEntries($pending))->toBeEmpty()
        ->and(implode("\n", $said))->not->toContain('capacity');
});

it('reports each move once, from the value the agent last read', function (): void {
    capacityService(atStart: 1, live: [3, 3, 2]);
    [$bridge, $pending] = capacityBridge();
    $said = [];

    capacityRun($bridge, $said);
    capacityRun($bridge, $said);

    // One report for one move, although the second read found the raised value again. Counted on
    // stderr, because the sink could not tell: a second entry would have replaced the first.
    expect(array_filter($said, fn (string $m): bool => str_contains($m, 'capacity')))->toHaveCount(1)
        ->and(capacityEntries($pending))->toHaveCount(1);

    // The agent reads it, as the stop hook would
    $pending->drain();

    capacityRun($bridge, $said);

    expect(capacityEntries($pending))->toBe(["This session's capacity changed from 3 to 2, so it may now hold up to 2 tasks at once."]);
});

it('reports a second move before the first is read from what the agent last saw', function (): void {
    capacityService(atStart: 1, live: [3, 2]);
    [$bridge, $pending] = capacityBridge();
    $said = [];

    capacityRun($bridge, $said);
    capacityRun($bridge, $said);

    // Replaced, not appended, and from 1: the agent never saw the 3
    expect(capacityEntries($pending))->toBe(["This session's capacity changed from 1 to 2, so it may now hold up to 2 tasks at once."]);
});

it('says so when the capacity moves and moves back before the agent reads it', function (): void {
    capacityService(atStart: 1, live: [3, 1]);
    [$bridge, $pending] = capacityBridge();
    $said = [];

    capacityRun($bridge, $said);
    capacityRun($bridge, $said);

    expect(capacityEntries($pending))->toBe(["This session's capacity changed and changed back: it is 1 again."]);
});

it('treats a start that reported no capacity as one', function (): void {
    capacityService(atStart: null, live: [1, 4]);
    [$bridge, $pending] = capacityBridge();
    $said = [];

    capacityRun($bridge, $said);

    expect(capacityEntries($pending))->toBeEmpty();

    capacityRun($bridge, $said);

    expect(capacityEntries($pending))->toBe(["This session's capacity changed from 1 to 4, so it may now hold up to 4 tasks at once."]);
});

it('reads an unanswered capacity as unknown rather than as a change', function (mixed $answer): void {
    capacityService(atStart: 2, live: [$answer, 5]);
    [$bridge, $pending] = capacityBridge();
    $said = [];

    capacityRun($bridge, $said);

    expect(capacityReads())->toBe(1)
        ->and(capacityEntries($pending))->toBeEmpty();

    // The baseline survived the unknown read: the next real answer is reported from the start's value
    capacityRun($bridge, $said);

    expect(capacityEntries($pending))->toBe(["This session's capacity changed from 2 to 5, so it may now hold up to 5 tasks at once."]);
})->with([
    'a body without the field' => [null],
    'a server error' => [fn () => Http::response('', 500)],
    'a string' => [fn () => Http::response(['capacity' => '3'], 200)],
]);

it('does not ask before a read is due', function (): void {
    capacityService(atStart: 1, live: [3]);
    [$bridge, $pending] = capacityBridge(capacitySeconds: Bridge::CAPACITY_CHECK_SECONDS);
    $said = [];

    capacityRun($bridge, $said);

    expect(capacityReads())->toBe(0)
        ->and(capacityEntries($pending))->toBeEmpty();
});

it('wakes an idle Claude Code agent with a channel notice when the capacity moves', function (): void {
    capacityService(atStart: 1, live: [3]);
    [$bridge] = capacityBridge(channel: true);
    $said = [];

    $written = capacityRun($bridge, $said, [CAPACITY_CHANGE_INITIALIZE, CAPACITY_CHANGE_INITIALIZED]);

    $notices = array_values(array_filter($written, fn (array $m): bool => ($m['method'] ?? null) === 'notifications/claude/channel'));

    expect($notices)->toHaveCount(1)
        ->and(data_get($notices, '0.params.meta'))->toBe(['new' => '1']);
});

it('reports from its own baseline when an earlier bridge left an entry unread', function (): void {
    capacityService(atStart: 2, live: [3]);
    [$bridge, $pending] = capacityBridge();
    $said = [];

    // The sink outlives the process that writes it, so an entry can be there before this bridge wrote any
    $pending->leaveNotice(Bridge::CAPACITY_CHANGED, 'left by an earlier bridge');

    capacityRun($bridge, $said);

    expect(capacityEntries($pending))->toBe(["This session's capacity changed from 2 to 3, so it may now hold up to 3 tasks at once."]);
});

it('tries again when the entry could not be written, rather than moving on', function (): void {
    capacityService(atStart: 1, live: [3, 3]);
    [$bridge, $pending] = capacityBridge();
    $said = [];

    // The sink's directory cannot be made: a file stands where it would go
    File::ensureDirectoryExists($this->state.'/robot-council');
    File::put($this->state.'/robot-council/pending', 'in the way');

    capacityRun($bridge, $said);

    expect(implode("\n", $said))->not->toContain('capacity changed from');

    File::delete($this->state.'/robot-council/pending');

    capacityRun($bridge, $said);

    expect(capacityEntries($pending))->toBe(["This session's capacity changed from 1 to 3, so it may now hold up to 3 tasks at once."]);
});

it('ends the entry with its session, and keeps the record the next session is owed', function (): void {
    capacityService(atStart: 1, live: [3]);
    [$bridge, $pending] = capacityBridge();
    $said = [];

    capacityRun($bridge, $said);
    $pending->leaveNotice(Bridge::SESSION_ENDED, 'The fleet ended session 7.');

    // What `mcp` does on the way out
    $pending->clearFleetEvents();

    // The control is the second entry: the clear kept a `bridge.` entry, so the capacity one was chosen
    expect(capacityEntries($pending))->toBeEmpty()
        ->and(array_column($pending->peek(), 'type'))->toBe([Bridge::SESSION_ENDED]);
});
