<?php

declare(strict_types=1);

/**
 * The bridge as a Claude Code channel: what it declares, and when it announces waiting events.
 *
 * **What these tests cannot show is whether Claude Code acts on any of it.** That was measured by
 * hand for cli#117 against a throwaway server, and for cli#62 against this bridge; these pin the
 * bytes the bridge writes, so a later change cannot quietly stop writing them.
 *
 * @command  vendor/bin/pest --compact tests/Feature/ChannelNoticeTest.php
 */
use App\Support\Bridge;
use App\Support\Credentials\Credential;
use App\Support\FleetFollower;
use App\Support\PendingEvents;
use App\Support\Session;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

const CHANNEL_SERVICE = 'https://fleet.example.test';
const CHANNEL_INSTALLATION = 'rcouncil_1|CHANNEL-INSTALLATION-0123456789';

const CHANNEL_INITIALIZE = '{"jsonrpc":"2.0","id":0,"method":"initialize","params":{"protocolVersion":"2025-11-25"}}';
const CHANNEL_INITIALIZED = '{"jsonrpc":"2.0","method":"notifications/initialized"}';

/**
 * A stream holding the given lines, positioned at the start.
 *
 * `tmpfile()` rather than `php://memory`, because `stream_select` needs a real descriptor.
 *
 * @param  list<string>  $lines  One protocol message each.
 * @return resource The stream.
 */
function channelInput(array $lines)
{
    $stream = tmpfile();

    fwrite($stream, implode("\n", $lines)."\n");
    rewind($stream);

    return $stream;
}

/**
 * Fake the service: a session, a feed page, and an MCP endpoint that answers by method.
 *
 * **One call per test.** `Http::fake()` merges stubs and the first match wins, so a second call
 * cannot replace a route this one registered; pass `$feed` instead.
 *
 * @param  list<array<string, mixed>>  $events  The feed page's events.
 * @param  string  $initializeResult  The `result` the service gives `initialize`, as JSON.
 * @param  mixed  $feed  A response for the feed route that replaces the page built from `$events`.
 */
function channelService(array $events = [], string $initializeResult = '{"protocolVersion":"2025-11-25","capabilities":{"tools":{}},"serverInfo":{"name":"robot-council","version":"1.0.0"}}', mixed $feed = null): void
{
    Http::fake([
        '*/api/sessions' => Http::response(['session_id' => 7, 'token' => 'rcouncil_2|T', 'expires_in' => 3600, 'feed_cursor' => 1, 'abilities' => []], 201),
        '*/api/sessions/7/heartbeat' => Http::response([], 204),
        '*/api/events*' => $feed ?? Http::response(['events' => $events, 'cursor' => 99], 200),
        '*/api/mcp' => function (Request $request) use ($initializeResult) {
            $message = json_decode($request->body(), true);
            $method = \is_array($message) ? ($message['method'] ?? null) : null;
            $id = \is_array($message) ? json_encode($message['id'] ?? null) : 'null';

            return match ($method) {
                'initialize' => Http::response('{"jsonrpc":"2.0","id":'.$id.',"result":'.$initializeResult.'}', 200),
                'tools/list' => Http::response('{"jsonrpc":"2.0","id":'.$id.',"result":{"tools":[]}}', 200),

                // A notification: `laravel/mcp` answers 202 with no body
                default => Http::response('', 202),
            };
        },
    ]);
}

/**
 * One directive, which concerns every session whoever posted it.
 *
 * @return array<string, mixed> The event as the feed serializes it.
 */
function channelDirective(int $id): array
{
    return [
        'id' => $id,
        'type' => 'directive',
        'body' => 'hold new migrations',
        'meta' => [],
        'created_at' => '2026-09-24T12:00:00+00:00',
        'actor' => ['session_id' => 9, 'github_login' => 'otherdev', 'coordinator_direct' => true],
    ];
}

/**
 * Run a bridge over the given input and return every line it wrote to stdout, decoded.
 *
 * **Every line must parse**, the same bar `BridgeTest` holds the bridge to: a harness reads this
 * stream, so a line that does not parse is a malformed message rather than a visible error.
 *
 * @param  list<string>  $lines  What the harness sends.
 * @param  bool  $withFollower  Whether the bridge follows the feed.
 * @param  bool  $channel  Whether the bridge acts as a channel, which only a Claude Code bridge does.
 * @param  resource|null  $in  Input to read instead of `$lines`, for one that arrives over time.
 * @return list<array<array-key, mixed>> What the bridge wrote.
 */
function channelRun(array $lines, bool $withFollower = true, bool $channel = true, $in = null): array
{
    $session = new Session(app(Factory::class), CHANNEL_SERVICE, new Credential(CHANNEL_INSTALLATION));
    $session->start();

    $follower = $withFollower
        ? new FleetFollower($session, CHANNEL_SERVICE, new PendingEvents(CHANNEL_SERVICE, 'claude', 'channel-probe'), 0)
        : null;

    $out = tmpfile();

    new Bridge($session, CHANNEL_SERVICE, follower: $follower, channel: $channel)
        ->run($in ?? channelInput($lines), $out, fn (string $m): null => null);

    rewind($out);

    $written = [];

    foreach (explode("\n", trim((string) stream_get_contents($out))) as $line) {
        if ($line === '') {
            continue;
        }

        $decoded = json_decode($line, true);

        expect($decoded)->toBeArray("stdout carried a line that is not a protocol message: {$line}");

        if (\is_array($decoded)) {
            $written[] = $decoded;
        }
    }

    return $written;
}

/**
 * The channel notices among what the bridge wrote.
 *
 * @param  list<array<array-key, mixed>>  $written  Every message the bridge wrote.
 * @return list<array<array-key, mixed>> The notices.
 */
function channelNotices(array $written): array
{
    return array_values(array_filter($written, fn (array $m): bool => ($m['method'] ?? null) === 'notifications/claude/channel'));
}

/**
 * A state directory this test file owns, so the sink never lands in a real home.
 */
function channelStateHome(): string
{
    static $path;

    if (! \is_string($path)) {
        $path = sys_get_temp_dir().'/channel-notice-'.bin2hex(random_bytes(6));
    }

    return $path;
}

beforeEach(function (): void {
    Http::preventStrayRequests();

    putenv('XDG_STATE_HOME='.channelStateHome());
});

afterEach(function (): void {
    putenv('XDG_STATE_HOME');

    // A directory usually, and a regular file after the test that makes the sink unwritable
    is_file(channelStateHome())
        ? File::delete(channelStateHome())
        : File::deleteDirectory(channelStateHome());
});

it('declares the channel capability and its instructions in the initialize result it relays', function (): void {
    channelService();

    [$result] = channelRun([CHANNEL_INITIALIZE]);

    expect($result['id'])->toBe(0)
        ->and(data_get($result, 'result.capabilities.experimental'))->toHaveKey(Bridge::CHANNEL_CAPABILITY)
        ->and(data_get($result, 'result.instructions'))->toBe(Bridge::CHANNEL_INSTRUCTIONS);
});

it("keeps the service's empty objects as objects, which an array decode would turn into lists", function (): void {
    channelService();

    $out = tmpfile();
    $session = new Session(app(Factory::class), CHANNEL_SERVICE, new Credential(CHANNEL_INSTALLATION));
    $session->start();

    new Bridge($session, CHANNEL_SERVICE, follower: new FleetFollower($session, CHANNEL_SERVICE, new PendingEvents(CHANNEL_SERVICE, 'claude', 'channel-probe'), 0), channel: true)
        ->run(channelInput([CHANNEL_INITIALIZE]), $out, fn (string $m): null => null);

    rewind($out);

    // The raw bytes, because a decoded comparison cannot tell `{}` from `[]`
    $raw = trim((string) stream_get_contents($out));

    expect($raw)->toContain('"tools":{}')
        ->and($raw)->toContain('"experimental":{"claude/channel":{}}')
        ->and($raw)->not->toContain('[]');
});

it('adds to instructions the service already gives rather than replacing them', function (): void {
    channelService(initializeResult: '{"protocolVersion":"2025-11-25","capabilities":{"tools":{},"experimental":{"other/thing":{}}},"instructions":"Use the fleet tools."}');

    [$result] = channelRun([CHANNEL_INITIALIZE]);

    expect(data_get($result, 'result.instructions'))->toBe("Use the fleet tools.\n\n".Bridge::CHANNEL_INSTRUCTIONS)
        ->and(data_get($result, 'result.capabilities.experimental'))->toHaveKeys(['other/thing', Bridge::CHANNEL_CAPABILITY]);
});

it('declares nothing when it has no follower, because nothing could ever be announced', function (): void {
    channelService();

    [$result] = channelRun([CHANNEL_INITIALIZE], withFollower: false);

    expect(data_get($result, 'result.capabilities'))->not->toHaveKey('experimental')
        ->and(data_get($result, 'result'))->not->toHaveKey('instructions');
});

it('leaves every response other than the initialize result untouched', function (): void {
    channelService();

    $written = channelRun([CHANNEL_INITIALIZE, CHANNEL_INITIALIZED, '{"jsonrpc":"2.0","id":1,"method":"tools/list"}']);

    $list = array_values(array_filter($written, fn (array $m): bool => ($m['id'] ?? null) === 1));

    expect($list)->toHaveCount(1)
        ->and(data_get($list, '0.result'))->toBe(['tools' => []]);
});

it('announces new events the follower left, once the harness has initialized', function (): void {
    channelService([channelDirective(11), channelDirective(12)]);

    $notices = channelNotices(channelRun([CHANNEL_INITIALIZE, CHANNEL_INITIALIZED]));

    expect($notices)->toHaveCount(1)
        ->and(data_get($notices, '0.params.content'))->toBe('New fleet events are waiting for this session.')
        ->and(data_get($notices, '0.params.meta'))->toBe(['new' => '2'])

        // A count and nothing else: the directive's text reaches the agent through the stop hook
        ->and(json_encode($notices[0]))->not->toContain('hold new migrations');
});

it('announces nothing before the harness has said it finished initializing', function (): void {
    channelService([channelDirective(11)]);

    // The same events as the test above, without `notifications/initialized`
    expect(channelNotices(channelRun([CHANNEL_INITIALIZE])))->toBeEmpty();
});

it('announces nothing when the feed held nothing for this session', function (): void {
    channelService([]);

    expect(channelNotices(channelRun([CHANNEL_INITIALIZE, CHANNEL_INITIALIZED])))->toBeEmpty();
});

it('still leaves the events in the sink, so a harness without channels loses nothing', function (): void {
    channelService([channelDirective(11)]);

    channelRun([CHANNEL_INITIALIZE, CHANNEL_INITIALIZED]);

    $drained = new PendingEvents(CHANNEL_SERVICE, 'claude', 'channel-probe')->drain();

    expect($drained)->toHaveCount(1);
});

it('counts only what the last tick wrote, so one batch is announced once', function (): void {
    Http::fake([
        '*/api/sessions' => Http::response(['session_id' => 7, 'token' => 'rcouncil_2|T', 'expires_in' => 3600, 'feed_cursor' => 1, 'abilities' => []], 201),
        '*/api/events*' => Http::sequence()
            ->push(['events' => [channelDirective(11)], 'cursor' => 11], 200)
            ->push(['events' => [], 'cursor' => 11], 200),
    ]);

    $session = new Session(app(Factory::class), CHANNEL_SERVICE, new Credential(CHANNEL_INSTALLATION));
    $session->start();

    $follower = new FleetFollower($session, CHANNEL_SERVICE, new PendingEvents(CHANNEL_SERVICE, 'claude', 'channel-probe'), 0);

    $follower->tick(fn (string $m): null => null);

    $first = $follower->delivered();

    $follower->tick(fn (string $m): null => null);

    expect($first)->toBe(1)
        ->and($follower->delivered())->toBe(0);
});

it('is not a channel for any harness but Claude Code, so another client sees the service unchanged', function (): void {
    channelService([channelDirective(11)]);

    $written = channelRun([CHANNEL_INITIALIZE, CHANNEL_INITIALIZED], channel: false);

    expect(data_get($written, '0.result.capabilities'))->not->toHaveKey('experimental')
        ->and(data_get($written, '0.result'))->not->toHaveKey('instructions')
        ->and(channelNotices($written))->toBeEmpty();
});

it('keeps a string id a string, and declares the channel on it', function (): void {
    channelService();

    [$result] = channelRun(['{"jsonrpc":"2.0","id":"init-1","method":"initialize","params":{"protocolVersion":"2025-11-25"}}']);

    expect($result['id'])->toBe('init-1')
        ->and(data_get($result, 'result.capabilities.experimental'))->toHaveKey(Bridge::CHANNEL_CAPABILITY);
});

it('relays an error answer to initialize untouched', function (): void {
    Http::fake([
        '*/api/sessions' => Http::response(['session_id' => 7, 'token' => 'rcouncil_2|T', 'expires_in' => 3600, 'feed_cursor' => 1, 'abilities' => []], 201),
        '*/api/events*' => Http::response(['events' => [], 'cursor' => 99], 200),
        '*/api/mcp' => Http::response('{"jsonrpc":"2.0","id":0,"error":{"code":-32602,"message":"Unsupported protocol version"}}', 200),
    ]);

    [$result] = channelRun([CHANNEL_INITIALIZE]);

    expect($result)->toBe(['jsonrpc' => '2.0', 'id' => 0, 'error' => ['code' => -32602, 'message' => 'Unsupported protocol version']]);
});

it('replaces an experimental entry that is not an object, which laravel/mcp sends as [] when empty', function (): void {
    channelService(initializeResult: '{"protocolVersion":"2025-11-25","capabilities":{"tools":{},"experimental":[]}}');

    [$result] = channelRun([CHANNEL_INITIALIZE]);

    expect(data_get($result, 'result.capabilities.experimental'))->toBe([Bridge::CHANNEL_CAPABILITY => []]);
});

it('sets the instructions when the service gives null for them', function (): void {
    channelService(initializeResult: '{"protocolVersion":"2025-11-25","capabilities":{"tools":{}},"instructions":null}');

    [$result] = channelRun([CHANNEL_INITIALIZE]);

    expect(data_get($result, 'result.instructions'))->toBe(Bridge::CHANNEL_INSTRUCTIONS);
});

it("relays the service's own bytes when the rewrite cannot be encoded, rather than no answer", function (): void {
    // `1e999` decodes to INF, which json_encode refuses
    channelService(initializeResult: '{"protocolVersion":"2025-11-25","capabilities":{"tools":{}},"limit":1e999}');

    $written = channelRun([CHANNEL_INITIALIZE]);

    expect($written)->toHaveCount(1)
        ->and(data_get($written, '0.id'))->toBe(0);
});

it('announces nothing when the sink could not be written, because the stop hook would find nothing', function (): void {
    channelService([channelDirective(11)]);

    // A regular file where the state directory should be, so the sink cannot be created under it
    File::ensureDirectoryExists(\dirname(channelStateHome()));
    file_put_contents(channelStateHome(), 'not a directory');

    expect(channelNotices(channelRun([CHANNEL_INITIALIZE, CHANNEL_INITIALIZED])))->toBeEmpty();
});

it('announces a batch that arrived before the harness finished initializing, once it has', function (): void {
    // One batch on the first read and nothing after, so a later read cannot re-deliver it and hide
    // a count that was dropped rather than kept
    channelService(feed: Http::sequence()
        ->push(['events' => [channelDirective(11)], 'cursor' => 11], 200)
        ->whenEmpty(Http::response(['events' => [], 'cursor' => 11], 200)));

    // `initialize` now and `initialized` a moment later, so the first feed read falls between them:
    // the order a real harness produces, which one stream read all at once cannot
    $feeder = proc_open(
        [\PHP_BINARY, '-r', 'echo $argv[1], "\n"; fflush(STDOUT); usleep(700000); echo $argv[2], "\n";', CHANNEL_INITIALIZE, CHANNEL_INITIALIZED],
        [1 => ['pipe', 'w']],
        $pipes
    );

    // A feeder that never started would read as a bridge that announced nothing
    expect($feeder)->toBeResource();

    if (! \is_resource($feeder)) {
        return;
    }

    $notices = channelNotices(channelRun([], in: $pipes[1]));

    proc_close($feeder);

    expect($notices)->toHaveCount(1);
})->skipOnWindows();
