<?php

declare(strict_types=1);

/**
 * The wake-delivery seam (#381): when the bridge hands a notice to a harness's adapter, and what a
 * failure leaves behind.
 *
 * **What these tests cannot show is whether any harness acts on a notice.** No real adapter exists
 * yet; each harness's own ticket measures that against the live harness. These drive the bridge's
 * periodic pass directly, because the loop reads `time()` inside `stream_select` and a test cannot
 * advance either, so a pass is run by hand against a clock the test moves.
 *
 * @command  vendor/bin/pest --compact tests/Feature/WakeDeliveryTest.php
 */
use App\Support\Bridge;
use App\Support\Credentials\Credential;
use App\Support\FleetFollower;
use App\Support\PendingEvents;
use App\Support\Session;
use App\Support\WakeAdapter;
use App\Support\WakeAdapters;
use App\Support\WakeDelivery;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\TwoStreamOutput;

const SEAM_SERVICE = 'https://fleet.example.test';
const SEAM_INSTALLATION = 'rcouncil_1|SEAM-INSTALLATION-0123456789';

/**
 * An adapter that records what it was handed and fails when told to.
 */
final class SeamAdapter implements WakeAdapter
{
    /** @var list<string> */
    public array $notices = [];

    /** @var list<string|null> What each call answers, in order; the last repeats. */
    public array $answers = [null];

    public function __construct(private readonly string $named = 'seat-A') {}

    public function target(): string
    {
        return $this->named;
    }

    public function deliver(string $notice): ?string
    {
        $this->notices[] = $notice;

        return $this->answers[min(\count($this->notices) - 1, \count($this->answers) - 1)];
    }
}

/**
 * A clock a test can move.
 */
final class SeamClock
{
    public int $now = 1_000_000;

    public function read(): int
    {
        return $this->now;
    }
}

/**
 * One directive, which concerns every session whoever posted it.
 *
 * @return array<string, mixed> The event as the feed serializes it.
 */
function seamDirective(int $id, string $body = 'hold new migrations'): array
{
    return [
        'id' => $id,
        'type' => 'directive',
        'body' => $body,
        'meta' => [],
        'created_at' => '2026-11-03T12:00:00+00:00',
        'actor' => ['session_id' => 9, 'github_login' => 'otherdev', 'coordinator_direct' => true],
    ];
}

/**
 * A bridge, its adapter, its clock, and a way to run one periodic pass.
 *
 * @param  list<list<array<string, mixed>>>  $pages  What each feed read returns, in order; empty after.
 * @param  bool  $wakeOn  Whether the seat opted in.
 * @return array{pass: Closure(): list<string>, adapter: SeamAdapter, clock: SeamClock, sink: PendingEvents, bridge: Bridge}
 */
function seamWorld(array $pages, bool $wakeOn = true, string $target = 'seat-A'): array
{
    $sequence = Http::sequence();

    foreach ($pages as $i => $events) {
        $sequence->push(['events' => $events, 'cursor' => 20 + $i], 200);
    }

    Http::fake([
        '*/api/sessions' => Http::response(['session_id' => 7, 'token' => 'rcouncil_2|T', 'expires_in' => 3600, 'feed_cursor' => 1, 'abilities' => []], 201),
        '*/api/sessions/7/heartbeat' => Http::response([], 204),
        '*/api/events*' => $sequence->whenEmpty(Http::response(['events' => [], 'cursor' => 99], 200)),
    ]);

    $session = new Session(app(Factory::class), SEAM_SERVICE, new Credential(SEAM_INSTALLATION));
    $session->start();

    $sink = new PendingEvents(SEAM_SERVICE, 'amp', 'seam-probe');
    $adapter = new SeamAdapter($target);
    $clock = new SeamClock;

    $bridge = new Bridge(
        $session,
        SEAM_SERVICE,
        follower: new FleetFollower($session, SEAM_SERVICE, $sink, 0),
        // As `McpCommand` builds it: a woken seat assumes no hook
        stopHook: ! $wakeOn,
        wake: $wakeOn ? new WakeDelivery($adapter, $clock->read(...)) : null,
    );

    // Nothing else in a pass is under test: push the heartbeat and capacity reads out of reach
    foreach (['nextHeartbeat', 'nextCapacityCheck'] as $property) {
        new ReflectionProperty(Bridge::class, $property)->setValue($bridge, PHP_INT_MAX);
    }

    $pass = function () use ($bridge): array {
        $said = [];

        new ReflectionMethod(Bridge::class, 'periodic')->invoke($bridge, tmpfile(), function (string $message) use (&$said): void {
            // Only the wake's own lines: the follower says things of its own
            if (str_starts_with($message, 'Wake ')) {
                $said[] = $message;
            }
        });

        return $said;
    };

    return ['pass' => $pass, 'adapter' => $adapter, 'clock' => $clock, 'sink' => $sink, 'bridge' => $bridge];
}

/**
 * A state directory this test file owns, so the sink never lands in a real home.
 */
function seamStateHome(): string
{
    static $path;

    if (! \is_string($path)) {
        $path = sys_get_temp_dir().'/wake-seam-'.bin2hex(random_bytes(6));
    }

    return $path;
}

beforeEach(function (): void {
    Http::preventStrayRequests();

    putenv('XDG_STATE_HOME='.seamStateHome());
});

afterEach(function (): void {
    putenv('XDG_STATE_HOME');
    putenv('ROBOT_COUNCIL_HARNESS');

    File::deleteDirectory(seamStateHome());
});

describe('a burst', function (): void {
    it('is announced once, and not again once the events have been read', function (): void {
        $world = seamWorld([[seamDirective(11), seamDirective(12), seamDirective(13)]]);

        ($world['pass'])();

        expect($world['adapter']->notices)->toBe([WakeDelivery::NOTICE]);

        // The agent reads: the stop hook, `pending` and anything else that reads the sink empty it
        $world['sink']->drain();
        $world['clock']->now += 3600;

        ($world['pass'])();
        ($world['pass'])();

        expect($world['adapter']->notices)->toHaveCount(1);
    });

    it('is one notice when it arrives over several passes inside the gap', function (): void {
        $world = seamWorld([[seamDirective(11)], [seamDirective(12)], [seamDirective(13)]]);

        ($world['pass'])();
        $world['clock']->now += WakeDelivery::MINIMUM_GAP_SECONDS - 1;
        ($world['pass'])();
        ($world['pass'])();

        expect($world['adapter']->notices)->toHaveCount(1);

        // And what arrived inside the gap is announced once it closes, not lost
        $world['clock']->now += 1;
        ($world['pass'])();

        expect($world['adapter']->notices)->toHaveCount(2);
    });

    it('carries no event text in the notice', function (): void {
        $world = seamWorld([[seamDirective(11, 'delete the production database')]]);

        ($world['pass'])();

        expect($world['adapter']->notices[0])->not->toContain('delete the production database')
            ->and(WakeDelivery::NOTICE)->toContain('events_read');
    });
});

describe('a seat that has not opted in, and a quiet fleet', function (): void {
    it('makes no delivery with waking off, whatever arrives', function (): void {
        $world = seamWorld([[seamDirective(11)]], wakeOn: false);

        ($world['pass'])();
        ($world['pass'])();

        expect($world['adapter']->notices)->toBeEmpty()
            ->and($world['sink']->peek())->toHaveCount(1);
    });

    it('makes no delivery on a quiet fleet', function (): void {
        $world = seamWorld([]);

        ($world['pass'])();
        $world['clock']->now += 3600;
        ($world['pass'])();

        expect($world['adapter']->notices)->toBeEmpty();
    });

    it('does not tell the agent how to read a notice unless waking is on', function (): void {
        $initialize = fn (bool $on): string => (function () use ($on): string {
            $world = seamWorld([], wakeOn: $on);
            $result = new ReflectionMethod(Bridge::class, 'initializeResult')->invoke($world['bridge'], ['protocolVersion' => '2025-11-25']);

            return \is_array($result) && \is_string($result['instructions'] ?? null) ? $result['instructions'] : '';
        })();

        expect($initialize(true))->toContain(WakeDelivery::INSTRUCTIONS)
            ->and($initialize(false))->not->toContain(WakeDelivery::INSTRUCTIONS);
    });
});

describe('a notice that was submitted and not acted on', function (): void {
    it('leaves the events pending and sends no second notice until something new arrives', function (): void {
        $world = seamWorld([[seamDirective(11)], [], [], [seamDirective(12)]]);

        ($world['pass'])();
        expect($world['adapter']->notices)->toHaveCount(1);

        // Time passes, the feed is read again and again, and nothing reads the events
        $world['clock']->now += 86400;
        ($world['pass'])();
        ($world['pass'])();

        expect($world['adapter']->notices)->toHaveCount(1)
            ->and($world['sink']->peek())->toHaveCount(1);

        // The pending state changes: now there is something to say
        ($world['pass'])();

        expect($world['adapter']->notices)->toHaveCount(2)
            ->and($world['sink']->peek())->toHaveCount(2);
    });
});

describe('a failing adapter', function (): void {
    it('leaves the events pending, names the target, and says it will try again', function (): void {
        $world = seamWorld([[seamDirective(11)]]);
        $world['adapter']->answers = ['no such thread'];

        $said = ($world['pass'])();

        expect($said)->toHaveCount(1)
            ->and($said[0])->toContain('"seat-A"')
            ->and($said[0])->toContain('no such thread')
            ->and($said[0])->toContain('trying again')
            ->and($world['sink']->peek())->toHaveCount(1);
    });

    it('is retried with doubling backoff, a bounded number of times, then left alone', function (): void {
        $world = seamWorld([[seamDirective(11)]]);
        $world['adapter']->answers = ['unreachable'];

        $gaps = [];

        for ($failure = 0; $failure < WakeDelivery::FAILURE_LIMIT; $failure++) {
            $before = \count($world['adapter']->notices);
            ($world['pass'])();
            expect($world['adapter']->notices)->toHaveCount($before + 1);

            // Too early: nothing is tried
            $world['clock']->now += 1;
            ($world['pass'])();
            expect($world['adapter']->notices)->toHaveCount($before + 1);

            $wait = WakeDelivery::RETRY_SECONDS << $failure;
            $gaps[] = $wait;
            $world['clock']->now += $wait - 1;
        }

        $tried = \count($world['adapter']->notices);

        // Past the limit, however long the wait
        $world['clock']->now += 86400;
        $said = ($world['pass'])();

        expect($gaps)->toBe([30, 60, 120, 240, 480])
            ->and($tried)->toBe(WakeDelivery::FAILURE_LIMIT)
            ->and($world['adapter']->notices)->toHaveCount(WakeDelivery::FAILURE_LIMIT)
            ->and($said)->toBeEmpty()
            ->and($world['sink']->peek())->toHaveCount(1);
    });

    it('says it has given up on the pass that used the last try', function (): void {
        $world = seamWorld([[seamDirective(11)]]);
        $world['adapter']->answers = ['unreachable'];

        $last = [];

        for ($failure = 0; $failure < WakeDelivery::FAILURE_LIMIT; $failure++) {
            $last = ($world['pass'])();
            $world['clock']->now += 3600;
        }

        expect($last[0])->toContain('giving up on this batch');
    });

    it('tries a later batch afresh', function (): void {
        $world = seamWorld([[seamDirective(11)], [], [], [], [], [], [seamDirective(12)]]);
        $world['adapter']->answers = ['unreachable', 'unreachable', 'unreachable', 'unreachable', 'unreachable', null];

        for ($failure = 0; $failure < WakeDelivery::FAILURE_LIMIT; $failure++) {
            ($world['pass'])();
            $world['clock']->now += 3600;
        }

        // Empty reads while it has given up, then the next batch
        ($world['pass'])();
        expect($world['adapter']->notices)->toHaveCount(WakeDelivery::FAILURE_LIMIT);

        ($world['pass'])();

        expect($world['adapter']->notices)->toHaveCount(WakeDelivery::FAILURE_LIMIT + 1);
    });

    it('treats an adapter that throws as a failed delivery, not a bridge that stops', function (): void {
        $throwing = new class implements WakeAdapter
        {
            public function target(): string
            {
                return 'seat-T';
            }

            public function deliver(string $notice): ?string
            {
                throw new RuntimeException('the harness crashed');
            }
        };

        $clock = new SeamClock;
        $delivery = new WakeDelivery($throwing, $clock->read(...));

        expect($delivery->deliver())->toBeFalse()
            ->and($delivery->lastFailure())->toBe('delivery to "seat-T" failed: the harness crashed');
    });

    it('stops retrying a batch the agent read through events_read in the meantime', function (): void {
        // No stop hook is assumed, so the agent's own read is the one that settles a notice, and it
        // leaves the sink as it was
        $world = seamWorld([[seamDirective(11)]]);
        $world['adapter']->answers = ['unreachable', null];

        ($world['pass'])();

        $call = '{"jsonrpc":"2.0","id":5,"method":"tools/call","params":{"name":"events_read","arguments":{}}}';
        new ReflectionMethod(Bridge::class, 'answerLocally')->invoke($world['bridge'], $call, tmpfile(), fn (string $m): null => null);

        $world['clock']->now += 3600;
        ($world['pass'])();

        expect($world['adapter']->notices)->toHaveCount(1)
            ->and($world['sink']->peek())->toHaveCount(1);
    });

    it('stops retrying a batch whose events were read in the meantime', function (): void {
        $world = seamWorld([[seamDirective(11)]]);
        $world['adapter']->answers = ['unreachable', null];

        ($world['pass'])();
        $world['sink']->drain();
        $world['clock']->now += 3600;
        ($world['pass'])();

        expect($world['adapter']->notices)->toHaveCount(1);
    });
});

describe('the target', function (): void {
    it('is the one configured, handed to its own adapter and nobody else', function (): void {
        $a = seamWorld([[seamDirective(11)]], target: 'seat-A');
        $b = seamWorld([[seamDirective(11)]], target: 'seat-B');

        ($a['pass'])();

        // Seat B's bridge never ran a pass, and seat A's adapter is the only one that heard
        expect($a['adapter']->target())->toBe('seat-A')
            ->and($a['adapter']->notices)->toHaveCount(1)
            ->and($b['adapter']->target())->toBe('seat-B')
            ->and($b['adapter']->notices)->toBeEmpty();
    });

    it('wakes only for an event addressed to its own session, never another seat in the same checkout', function (int $addressee, int $wakes): void {
        // This bridge is session 7. The follower decides what concerns a session before anything
        // reaches the sink, so the wake inherits the recipient rules (#269) rather than restating them
        $world = seamWorld([[[
            'id' => 21,
            'type' => 'placement.instruction',
            'body' => 'Branch off main.',
            'meta' => ['task_id' => 3, 'to' => [$addressee]],
            'created_at' => '2026-11-03T12:00:00+00:00',
            'actor' => ['session_id' => 9, 'github_login' => 'otherdev', 'coordinator_direct' => true],
        ]]]);

        ($world['pass'])();

        expect($world['adapter']->notices)->toHaveCount($wakes);
    })->with([
        'another seat in the checkout' => [8, 0],
        'this one' => [7, 1],
    ]);
});

describe('the adapters', function (): void {
    it('hands a harness the target it was configured with', function (): void {
        $adapters = new WakeAdapters(['amp' => fn (string $target): WakeAdapter => new SeamAdapter($target)]);

        $adapter = $adapters->for('amp', 'thread-9');

        expect($adapter)->toBeInstanceOf(WakeAdapter::class)
            ->and($adapter instanceof WakeAdapter ? $adapter->target() : '')->toBe('thread-9');
    });

    it('says why there is none for a harness without one, or for a target the adapter refuses', function (): void {
        $adapters = new WakeAdapters(['amp' => fn (string $target): WakeAdapter => throw new InvalidArgumentException('"'.$target.'" is not a thread')]);

        expect($adapters->for('cursor', 'x'))->toBe('there is no wake adapter for the "cursor" harness')
            ->and($adapters->for('amp', 'bad one'))->toBe('"bad one" is not a thread')
            ->and(new WakeAdapters)->for('amp', 'x')->toBeString();
    });
});

describe('the option', function (): void {
    it('is off by default, and says nothing about waking', function (): void {
        putenv('ROBOT_COUNCIL_HARNESS=amp');
        $output = new TwoStreamOutput;

        Artisan::call('mcp', ['--service' => SEAM_SERVICE], $output);

        expect($output->stderr())->not->toContain('wake');
    });

    it('says a harness without an adapter cannot be woken, and carries on', function (): void {
        putenv('ROBOT_COUNCIL_HARNESS=amp');
        $output = new TwoStreamOutput;

        $exit = Artisan::call('mcp', ['--service' => SEAM_SERVICE, '--wake-target' => 'thread-9'], $output);

        expect($exit)->toBe(0)
            ->and($output->stderr())->toContain('--wake-target: there is no wake adapter for the "amp" harness; waking is off.')
            ->and($output->stdout())->toBeEmpty();
    });

    it('is not for Claude Code, whose channel stays as it is', function (): void {
        putenv('ROBOT_COUNCIL_HARNESS=claude');
        $output = new TwoStreamOutput;

        Artisan::call('mcp', ['--service' => SEAM_SERVICE, '--wake-target' => 'thread-9'], $output);

        expect($output->stderr())->toContain('--wake-target does not apply to Claude Code');
    });

    it('says a bare option needs a value', function (): void {
        putenv('ROBOT_COUNCIL_HARNESS=amp');
        $output = new TwoStreamOutput;

        Artisan::call('mcp', ['--service' => SEAM_SERVICE, '--wake-target' => null], $output);

        expect($output->stderr())->toContain('--wake-target takes the id of the seat to wake');
    });

    it('builds the adapter its harness registered, with the target given', function (): void {
        putenv('ROBOT_COUNCIL_HARNESS=amp');
        $targets = [];

        app()->instance(WakeAdapters::class, new WakeAdapters(['amp' => function (string $target) use (&$targets): WakeAdapter {
            $targets[] = $target;

            return new SeamAdapter($target);
        }]));

        $output = new TwoStreamOutput;

        Artisan::call('mcp', ['--service' => SEAM_SERVICE, '--wake-target' => 'thread-9'], $output);

        expect($targets)->toBe(['thread-9'])
            ->and($output->stderr())->not->toContain('waking is off');
    });
});
