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
 * @param  int  $reannounceSeconds  How long a notice waits before it is sent again; 0 makes every pass due.
 * @param  string|null  $outPath  A file to write to instead of an anonymous one, for a feeder that waits on it.
 * @return list<array<array-key, mixed>> What the bridge wrote.
 */
function channelRun(array $lines, bool $withFollower = true, bool $channel = true, $in = null, int $reannounceSeconds = Bridge::REANNOUNCE_SECONDS, ?string $outPath = null, bool $stopHook = true): array
{
    $session = new Session(app(Factory::class), CHANNEL_SERVICE, new Credential(CHANNEL_INSTALLATION));
    $session->start();

    $follower = $withFollower
        ? new FleetFollower($session, CHANNEL_SERVICE, new PendingEvents(CHANNEL_SERVICE, 'claude', 'channel-probe'), 0)
        : null;

    $out = $outPath === null ? tmpfile() : fopen($outPath, 'w+');

    new Bridge($session, CHANNEL_SERVICE, follower: $follower, channel: $channel, reannounceSeconds: $reannounceSeconds, stopHook: $stopHook)
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
 * Whether the fake service was sent a message with the given method.
 */
function channelServiceWasAskedFor(string $method): bool
{
    $asked = false;

    Http::assertSent(function (Request $request) use ($method, &$asked): bool {
        if (str_contains($request->url(), '/api/mcp') && data_get(json_decode($request->body(), true), 'method') === $method) {
            $asked = true;
        }

        return true;
    });

    return $asked;
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

it('declares the channel capability and its instructions in the initialize result it answers', function (): void {
    channelService();

    [$result] = channelRun([CHANNEL_INITIALIZE]);

    expect($result['id'])->toBe(0)
        ->and(data_get($result, 'result.capabilities.experimental'))->toHaveKey(Bridge::CHANNEL_CAPABILITY)
        ->and(data_get($result, 'result.instructions'))->toContain(Bridge::CHANNEL_INSTRUCTIONS)

        // The handshake is the bridge's own since cli#127, so the fleet is never asked for it
        ->and(channelServiceWasAskedFor('initialize'))->toBeFalse();
});

it('writes the empty capability objects as objects, which a harness reads as declared', function (): void {
    channelService();

    $out = tmpfile();
    $session = new Session(app(Factory::class), CHANNEL_SERVICE, new Credential(CHANNEL_INSTALLATION));
    $session->start();

    new Bridge($session, CHANNEL_SERVICE, follower: new FleetFollower($session, CHANNEL_SERVICE, new PendingEvents(CHANNEL_SERVICE, 'claude', 'channel-probe'), 0), channel: true)
        ->run(channelInput([CHANNEL_INITIALIZE]), $out, fn (string $m): null => null);

    rewind($out);

    // The raw bytes, because a decoded comparison cannot tell `{}` from `[]`
    $raw = trim((string) stream_get_contents($out));

    expect($raw)->toContain('"experimental":{"claude/channel":{}}')
        ->and($raw)->toContain('"prompts":{}')
        ->and($raw)->not->toContain('[]');
});

it('declares nothing when no follower can exist, because nothing could ever be announced', function (): void {
    channelService();

    [$result] = channelRun([CHANNEL_INITIALIZE], withFollower: false);

    expect(data_get($result, 'result.capabilities'))->not->toHaveKey('experimental')
        ->and(data_get($result, 'result.instructions'))->not->toContain(Bridge::CHANNEL_INSTRUCTIONS);
});

it('is not a channel for any harness but Claude Code', function (): void {
    channelService([channelDirective(11)]);

    $written = channelRun([CHANNEL_INITIALIZE, CHANNEL_INITIALIZED], channel: false);

    expect(data_get($written, '0.result.capabilities'))->not->toHaveKey('experimental')
        ->and(data_get($written, '0.result.instructions'))->not->toContain(Bridge::CHANNEL_INSTRUCTIONS)
        ->and(channelNotices($written))->toBeEmpty();
});

it('keeps a string id a string', function (): void {
    channelService();

    [$result] = channelRun(['{"jsonrpc":"2.0","id":"init-1","method":"initialize","params":{"protocolVersion":"2025-11-25"}}']);

    expect($result['id'])->toBe('init-1')
        ->and(data_get($result, 'result.capabilities.experimental'))->toHaveKey(Bridge::CHANNEL_CAPABILITY);
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

/**
 * A child process that plays the harness's side over time, one step at a time.
 *
 * **Synchronized on what the bridge wrote, never on a pause.** `run()` reads whatever has arrived,
 * forwards it, and does its periodic work once, so two lines that arrive together share a pass.
 * Fixed pauses made that a race the machine's load decided: measured, one run in eighteen under
 * load announced the first notice AFTER the feeder had rewritten the sink, and fingerprinted the
 * rewrite. So a step can wait for a string to appear in the bridge's output -- a ping's reply
 * proves the bridge read that ping, so the next line lands in a later pass.
 *
 * Steps are a line to write, `['await' => <string>]`, or `['put' => <path>, 'with' => <contents>]`,
 * which rewrites the sink between passes: empty is what a stop hook's drain leaves. An await gives
 * up after ten seconds, so a bridge that never writes fails the assertions rather than hanging.
 *
 * @param  list<string|array{await: string}|array{put: string, with: string}>  $steps  What the harness does, in order.
 * @param  string  $outPath  The file the bridge writes, which awaits read.
 * @return array{0: resource, 1: resource} The process, and its stdout for the bridge to read.
 */
function channelFeeder(array $steps, string $outPath): array
{
    $feeder = proc_open(
        [\PHP_BINARY, '-r', 'foreach (json_decode($argv[1], true) as $s) {
            if (is_string($s)) { echo $s, "\n"; fflush(STDOUT); }
            elseif (isset($s["await"])) {
                $until = microtime(true) + 10;
                while (! str_contains((string) file_get_contents($argv[2]), $s["await"]) && microtime(true) < $until) { usleep(10000); }
            }
            else { file_put_contents($s["put"], $s["with"]); }
        }', (string) json_encode($steps), $outPath],
        [1 => ['pipe', 'w']],
        $pipes
    );

    // A feeder that never started would read as a bridge that announced nothing
    if (! \is_resource($feeder)) {
        throw new RuntimeException('The feeder process did not start.');
    }

    return [$feeder, $pipes[1]];
}

/**
 * `initialize`, then `initialized` in a pass of its own, and -- when one is expected -- the first
 * notice written before anything follows.
 *
 * `initialized` has no reply to wait on, so the notice is what marks its pass done. Where no notice
 * is expected, whether the next line shares its pass cannot change a count of zero.
 *
 * @return list<string|array{await: string}> Feeder steps.
 */
function channelHandshake(bool $announces = true): array
{
    return [
        CHANNEL_INITIALIZE, ['await' => '"id":0,'],
        CHANNEL_INITIALIZED,
        ...($announces ? [['await' => 'notifications/claude/channel']] : []),
    ];
}

/**
 * `count` pings, each written only once the one before it was answered, so each is its own pass.
 *
 * @return list<string|array{await: string}> Feeder steps.
 */
function channelPings(int $count, int $from = 100): array
{
    $steps = [];

    for ($i = 0; $i < $count; $i++) {
        $steps[] = '{"jsonrpc":"2.0","id":'.($from + $i).',"method":"ping"}';
        $steps[] = ['await' => '"id":'.($from + $i).','];
    }

    return $steps;
}

/**
 * A feed that serves the given pages in order and nothing after them.
 *
 * @param  list<list<array<string, mixed>>>  $pages  Each read's events.
 */
function channelPages(array $pages): mixed
{
    $sequence = Http::sequence();

    foreach ($pages as $i => $events) {
        $sequence->push(['events' => $events, 'cursor' => 20 + $i], 200);
    }

    return $sequence->whenEmpty(Http::response(['events' => [], 'cursor' => 99], 200));
}

/**
 * Run a bridge that re-announces on every due pass, fed by `channelFeeder()`, and return its notices' meta.
 *
 * @param  list<string|array{await: string}|array{put: string, with: string}>  $steps  What the harness does.
 * @return list<mixed> Each notice's meta, in order.
 */
function channelRepeats(array $steps, bool $channel = true, int $reannounceSeconds = 0, bool $stopHook = true): array
{
    $outPath = (string) tempnam(sys_get_temp_dir(), 'channel-out-');

    [$feeder, $stdout] = channelFeeder($steps, $outPath);

    $notices = channelNotices(channelRun([], channel: $channel, in: $stdout, reannounceSeconds: $reannounceSeconds, outPath: $outPath, stopHook: $stopHook));

    proc_close($feeder);
    File::delete($outPath);

    return array_map(fn (array $notice): mixed => data_get($notice, 'params.meta'), $notices);
}

/**
 * The sink the bridges in this file write, for a step that rewrites it.
 */
function channelSink(): string
{
    return new PendingEvents(CHANNEL_SERVICE, 'claude', 'channel-probe')->path();
}

it('announces a sink nothing drained again, up to the bound, and then stops', function (): void {
    // The #211 shape: the notice was spent inside a continuation turn and nothing drained the
    // sink, so without a repeat the session sits idle with a directive waiting
    channelService(feed: channelPages([[channelDirective(11)]]));

    // Two more passes than the bound, so a repeat past it would be seen
    $metas = channelRepeats([...channelHandshake(), ...channelPings(Bridge::REANNOUNCE_LIMIT + 2)]);

    $expected = [['new' => '1']];

    for ($i = 1; $i <= Bridge::REANNOUNCE_LIMIT; $i++) {
        $expected[] = ['new' => '1', 'repeat' => (string) $i];
    }

    expect($metas)->toBe($expected);
})->skipOnWindows();

it('waits the interval before announcing again', function (): void {
    // The control for the test above: the same input, with the real interval rather than 0, so
    // the repeats there are the interval elapsing and not something every pass does
    channelService(feed: channelPages([[channelDirective(11)]]));

    $metas = channelRepeats([...channelHandshake(), ...channelPings(Bridge::REANNOUNCE_LIMIT + 2)], reannounceSeconds: Bridge::REANNOUNCE_SECONDS);

    expect($metas)->toBe([['new' => '1']]);
})->skipOnWindows();

it('doubles the wait before each repeat, so the last lands half an hour after the notice', function (): void {
    // The schedule itself, which the loop cannot show: it reads `time()`. Evenly spaced repeats
    // would all be spent inside one long continuation turn, the case they exist for (cli#230).
    $waits = array_map(fn (int $sent): int => Bridge::reannounceDelay(Bridge::REANNOUNCE_SECONDS, $sent), range(0, Bridge::REANNOUNCE_LIMIT - 1));

    expect($waits)->toBe([60, 120, 240, 480, 960])
        ->and(array_sum($waits))->toBe(1860)

        // And a zero stays zero, which is what lets the tests above make every pass due
        ->and(Bridge::reannounceDelay(0, 4))->toBe(0);
});

it('stops announcing once the sink has been drained', function (): void {
    channelService(feed: channelPages([[channelDirective(11)]]));

    // One repeat, then a stop hook drains, then passes enough to repeat again if it were wrong
    $metas = channelRepeats([
        ...channelHandshake(),
        ...channelPings(1),
        ['await' => '"repeat":"1"'], ['put' => channelSink(), 'with' => ''],
        ...channelPings(3, from: 200),
    ]);

    expect($metas)->toBe([
        ['new' => '1'],
        ['new' => '1', 'repeat' => '1'],
    ]);
})->skipOnWindows();

it('stops announcing once the sink has changed some other way', function (): void {
    // Different contents that are still not empty: what the notice announced is no longer what is
    // waiting, so repeating it would be a claim about a sink that no longer exists. The empty case
    // above cannot tell this check from a plain emptiness check; this can.
    channelService(feed: channelPages([[channelDirective(11)]]));

    $metas = channelRepeats([
        ...channelHandshake(),
        ...channelPings(1),
        ['await' => '"repeat":"1"'], ['put' => channelSink(), 'with' => (string) json_encode([channelDirective(77)])],
        ...channelPings(3, from: 200),
    ]);

    expect($metas)->toBe([
        ['new' => '1'],
        ['new' => '1', 'repeat' => '1'],
    ]);
})->skipOnWindows();

it('announces a new batch as new, and counts its repeats afresh', function (): void {
    // A second directive on the fourth read: the sink changed because more arrived, which is news
    // in its own right rather than a repeat of the first
    channelService(feed: channelPages([[channelDirective(11)], [], [], [channelDirective(12)]]));

    $metas = channelRepeats([...channelHandshake(), ...channelPings(3)]);

    expect($metas)->toBe([
        ['new' => '1'],
        ['new' => '1', 'repeat' => '1'],
        ['new' => '1'],
        ['new' => '1', 'repeat' => '1'],
    ]);
})->skipOnWindows();

it('never announces an empty sink, however many passes are due', function (): void {
    channelService(feed: channelPages([]));

    expect(channelRepeats([...channelHandshake(false), ...channelPings(3)]))->toBeEmpty();
})->skipOnWindows();

it('repeats nothing for a harness that is not a channel', function (): void {
    // A pin on behavior that predates repeats -- `announce()` returns at its first line for such
    // a harness -- kept so a later reordering cannot start repeating to one
    channelService(feed: channelPages([[channelDirective(11)]]));

    expect(channelRepeats([...channelHandshake(false), ...channelPings(3)], channel: false))->toBeEmpty();
})->skipOnWindows();

it('repeats nothing before the harness has initialized', function (): void {
    // Also a pin rather than a test of the repeat logic: with no first notice there is nothing to
    // repeat, whatever `reannounce()` does
    channelService(feed: channelPages([[channelDirective(11)]]));

    expect(channelRepeats([CHANNEL_INITIALIZE, ...channelPings(3)]))->toBeEmpty();
})->skipOnWindows();

it('stops announcing once the agent has read the feed itself, when no stop hook will drain the sink', function (bool $stopHook, int $notices): void {
    // #306: with no hook nothing drains the sink, so a repeat would otherwise wake the agent six
    // times over half an hour for one batch it has already read
    channelService(feed: channelPages([[channelDirective(11)]]));

    $metas = channelRepeats([
        ...channelHandshake(),
        '{"jsonrpc":"2.0","id":50,"method":"tools/call","params":{"name":"'.Bridge::FEED_READ_TOOL.'","arguments":{}}}',
        ...channelPings(Bridge::REANNOUNCE_LIMIT + 2),
    ], stopHook: $stopHook);

    expect($metas)->toHaveCount($notices);
})->with([
    // The control: with a hook, reading the feed is not what settles a notice, so nothing changes
    'with a hook' => [true, Bridge::REANNOUNCE_LIMIT + 1],
    'without one' => [false, 1],
])->skipOnWindows();
