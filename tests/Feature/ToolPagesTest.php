<?php

declare(strict_types=1);

/**
 * The whole tool list in one reply, for a harness that never asks for a second page (cli#209).
 *
 * @command  vendor/bin/pest --compact tests/Feature/ToolPagesTest.php
 */
use App\Support\Bridge;
use App\Support\Credentials\Credential;
use App\Support\Session;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

const PAGES_SERVICE = 'https://fleet.example.test';

/**
 * A tool as the fleet describes it, with the empty `properties` object a real schema carries.
 */
function pagedTool(string $name): string
{
    return '{"name":"'.$name.'","inputSchema":{"type":"object","properties":{}}}';
}

/**
 * A fake fleet serving the given pages of `tools/list`, keyed by the cursor that asks for each.
 *
 * @param  array<string, array{tools: list<string>, next: string|null}>  $pages  `''` is the first page.
 */
function pagedFleet(array $pages): void
{
    Http::fake([
        '*/api/sessions' => Http::response(['session_id' => 5, 'token' => 'rcouncil_2|T', 'expires_in' => 3600], 201),
        '*/api/mcp' => function (Request $request) use ($pages) {
            $message = json_decode($request->body(), true);
            $cursor = data_get($message, 'params.cursor') ?? '';
            $page = $pages[is_string($cursor) ? $cursor : ''] ?? ['tools' => [], 'next' => null];

            $result = '{"tools":['.implode(',', $page['tools']).']'.($page['next'] === null ? '' : ',"nextCursor":"'.$page['next'].'"').'}';

            return Http::response('{"jsonrpc":"2.0","id":'.json_encode(data_get($message, 'id')).',"result":'.$result.'}', 200);
        },
    ]);
}

/**
 * Run a joined bridge over one message and return the raw line it wrote.
 */
function pagedRun(string $message): string
{
    $session = new Session(app(Factory::class), PAGES_SERVICE, new Credential('rcouncil_1|PAGES'));
    $session->start();

    $in = tmpfile();
    fwrite($in, $message."\n");
    rewind($in);
    $out = tmpfile();

    new Bridge($session, PAGES_SERVICE)->run($in, $out, fn (string $m): null => null);

    rewind($out);

    return trim((string) stream_get_contents($out));
}

/**
 * How many `tools/list` requests reached the fleet.
 */
function pagedRequests(): int
{
    return Http::recorded(fn (Request $request): bool => str_contains($request->url(), '/api/mcp'))->count();
}

beforeEach(function (): void {
    Http::preventStrayRequests();
});

it('answers the first page with every tool the fleet pages, and no cursor left', function (): void {
    pagedFleet([
        '' => ['tools' => [pagedTool('task_list'), pagedTool('task_create')], 'next' => 'page-2'],
        'page-2' => ['tools' => [pagedTool('events_narrate'), pagedTool('directive_post')], 'next' => 'page-3'],
        'page-3' => ['tools' => [pagedTool('presence_heartbeat')], 'next' => null],
    ]);

    $raw = pagedRun('{"jsonrpc":"2.0","id":7,"method":"tools/list"}');
    $reply = json_decode($raw, true);

    expect(data_get($reply, 'result.tools.*.name'))->toBe(['task_list', 'task_create', 'events_narrate', 'directive_post', 'presence_heartbeat'])
        ->and(data_get($reply, 'result'))->not->toHaveKey('nextCursor')
        ->and(data_get($reply, 'id'))->toBe(7)
        ->and(pagedRequests())->toBe(3)

        // The raw bytes, because a decoded comparison cannot tell `{}` from `[]`
        ->and($raw)->toContain('"properties":{}')
        ->and($raw)->not->toContain('"properties":[]');
});

it('relays a request that names a cursor as asked, for a harness that pages itself', function (): void {
    // `page-2` has a page after it, so a bridge that paged anyway would be seen following it
    pagedFleet([
        '' => ['tools' => [pagedTool('task_list')], 'next' => 'page-2'],
        'page-2' => ['tools' => [pagedTool('directive_post')], 'next' => 'page-3'],
        'page-3' => ['tools' => [pagedTool('presence_heartbeat')], 'next' => null],
    ]);

    $reply = json_decode(pagedRun('{"jsonrpc":"2.0","id":8,"method":"tools/list","params":{"cursor":"page-2"}}'), true);

    expect(data_get($reply, 'result.tools.*.name'))->toBe(['directive_post'])
        ->and(data_get($reply, 'result.nextCursor'))->toBe('page-3')
        ->and(pagedRequests())->toBe(1);
});

it('relays a single page untouched', function (): void {
    pagedFleet(['' => ['tools' => [pagedTool('task_list')], 'next' => null]]);

    expect(pagedRun('{"jsonrpc":"2.0","id":9,"method":"tools/list"}'))->toBe('{"jsonrpc":"2.0","id":9,"result":{"tools":['.pagedTool('task_list').']}}')
        ->and(pagedRequests())->toBe(1);
});

it('falls back to the first page when a cursor repeats, rather than looping', function (): void {
    pagedFleet([
        '' => ['tools' => [pagedTool('task_list')], 'next' => 'again'],
        'again' => ['tools' => [pagedTool('task_create')], 'next' => 'again'],
    ]);

    $reply = json_decode(pagedRun('{"jsonrpc":"2.0","id":10,"method":"tools/list"}'), true);

    expect(data_get($reply, 'result.tools.*.name'))->toBe(['task_list'])
        ->and(data_get($reply, 'result.nextCursor'))->toBe('again')
        ->and(pagedRequests())->toBe(2);
});

it('gives up after the page bound and relays the first page', function (): void {
    $pages = ['' => ['tools' => [pagedTool('t0')], 'next' => 'c1']];

    for ($i = 1; $i <= Bridge::MAX_TOOL_PAGES + 2; $i++) {
        $pages['c'.$i] = ['tools' => [pagedTool('t'.$i)], 'next' => 'c'.($i + 1)];
    }

    pagedFleet($pages);

    $reply = json_decode(pagedRun('{"jsonrpc":"2.0","id":11,"method":"tools/list"}'), true);

    expect(data_get($reply, 'result.tools.*.name'))->toBe(['t0'])
        ->and(pagedRequests())->toBe(Bridge::MAX_TOOL_PAGES);
});

it('leaves every other method alone, cursor or not', function (): void {
    pagedFleet(['' => ['tools' => [pagedTool('task_list')], 'next' => 'page-2']]);

    // Not a list of tools, so a `nextCursor` in its answer is the service's business
    $raw = pagedRun('{"jsonrpc":"2.0","id":12,"method":"resources/list"}');

    expect(data_get(json_decode($raw, true), 'result.nextCursor'))->toBe('page-2')
        ->and(pagedRequests())->toBe(1);
});
