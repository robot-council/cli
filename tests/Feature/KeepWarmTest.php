<?php

declare(strict_types=1);

/**
 * Keeping an idle Claude Code session's prompt cache warm from the bridge (#279).
 *
 * **What these tests cannot show is that a woken turn does nothing**, nor that the cache stayed
 * warm; both need a live session, and #279 records that measurement. These pin when the bridge
 * sends a keep-alive, what it sends, and that a bridge without the option sends nothing new.
 *
 * @command  vendor/bin/pest --compact tests/Feature/KeepWarmTest.php
 */

use App\Support\Bridge;
use App\Support\Credentials\Credential;
use App\Support\FleetFollower;
use App\Support\KeepWarm;
use App\Support\PendingEvents;
use App\Support\Session;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\TwoStreamOutput;

const WARM_SERVICE = 'https://fleet.example.test';

/**
 * A clock and a turn-end mark a test moves by hand.
 */
final class WarmWorld
{
    public int $now = 1_000_000;

    public ?int $mark = null;

    /**
     * A schedule over this world.
     */
    public function keepWarm(int $interval = 3300, ?int $ceiling = null): KeepWarm
    {
        return new KeepWarm($interval, $ceiling, fn (): ?int => $this->mark, fn (): int => $this->now);
    }

    /**
     * A turn ends now, as the stop hook would record it.
     */
    public function turnEnds(): void
    {
        $this->mark = $this->now;
    }
}

/**
 * Run a Claude Code bridge over a handshake and return every message it wrote.
 *
 * @param  list<string>  $extra  Messages after the handshake.
 * @return list<array<array-key, mixed>> What the bridge wrote.
 */
function warmRun(?KeepWarm $keepWarm, bool $channel = true, array $extra = [], bool $initialized = true): array
{
    Http::fake([
        '*/api/sessions' => Http::response(['session_id' => 7, 'token' => 'rcouncil_2|T', 'expires_in' => 3600, 'feed_cursor' => 1, 'abilities' => []], 201),
        '*/api/sessions/7/heartbeat' => Http::response([], 204),
        '*/api/agent/watcher' => Http::response([], 204),
        '*/api/events*' => Http::response(['events' => [], 'cursor' => 99], 200),
        '*/api/mcp' => fn (Request $request) => Http::response('{"jsonrpc":"2.0","id":'.json_encode(data_get(json_decode($request->body(), true), 'id')).',"result":{"content":[]}}', 200),
    ]);

    $session = new Session(app(Factory::class), WARM_SERVICE, new Credential('rcouncil_1|WARM-INSTALLATION'));
    $session->start();

    $follower = new FleetFollower($session, WARM_SERVICE, new PendingEvents(WARM_SERVICE, 'claude', 'warm-probe'), 0);

    $in = tmpfile();
    fwrite($in, implode("\n", [
        '{"jsonrpc":"2.0","id":0,"method":"initialize","params":{"protocolVersion":"2025-11-25"}}',
        ...($initialized ? ['{"jsonrpc":"2.0","method":"notifications/initialized"}'] : []),
        ...$extra,
    ])."\n");
    rewind($in);

    $out = tmpfile();

    new Bridge($session, WARM_SERVICE, follower: $follower, channel: $channel, keepWarm: $keepWarm)
        ->run($in, $out, fn (string $m): null => null);

    rewind($out);

    $written = [];

    foreach (explode("\n", trim((string) stream_get_contents($out))) as $line) {
        $decoded = json_decode($line, true);

        expect($decoded)->toBeArray("stdout carried a line that is not a protocol message: {$line}");

        if (\is_array($decoded)) {
            $written[] = $decoded;
        }
    }

    return $written;
}

/**
 * The keep-alive notices among what the bridge wrote.
 *
 * @param  list<array<array-key, mixed>>  $written
 * @return list<array<array-key, mixed>>
 */
function warmNotices(array $written): array
{
    return array_values(array_filter(
        $written,
        fn (array $m): bool => ($m['method'] ?? null) === 'notifications/claude/channel' && data_get($m, 'params.meta.keep_warm') !== null
    ));
}

beforeEach(function (): void {
    $this->stateDirectory = sys_get_temp_dir().'/rc-warm-'.bin2hex(random_bytes(6));

    putenv('XDG_STATE_HOME='.$this->stateDirectory);
    putenv('ROBOT_COUNCIL_SERVICE='.WARM_SERVICE);
    putenv('ROBOT_COUNCIL_HARNESS=claude');

    Http::preventStrayRequests();
});

afterEach(function (): void {
    File::deleteDirectory($this->stateDirectory);

    putenv('XDG_STATE_HOME');
    putenv('ROBOT_COUNCIL_SERVICE');
    putenv('ROBOT_COUNCIL_HARNESS');
});

describe('the schedule', function (): void {
    it('is not due until the session has been idle for the interval', function (): void {
        $world = new WarmWorld;
        $keepWarm = $world->keepWarm(3300);

        $world->now += 3299;
        expect($keepWarm->due())->toBeFalse();

        $world->now += 1;
        expect($keepWarm->due())->toBeTrue();
    });

    it('sends one keep-alive per interval of silence, not one per pass', function (): void {
        $world = new WarmWorld;
        $keepWarm = $world->keepWarm(3300);

        $world->now += 3300;
        $keepWarm->sent();

        $world->now += 3299;
        expect($keepWarm->due())->toBeFalse();

        $world->now += 1;
        expect($keepWarm->due())->toBeTrue();
    });

    it('restarts the idle clock on a tool call or a notice', function (): void {
        $world = new WarmWorld;
        $keepWarm = $world->keepWarm(3300);

        $world->now += 3000;
        $keepWarm->active();

        $world->now += 3000;
        expect($keepWarm->due())->toBeFalse();

        $world->now += 300;
        expect($keepWarm->due())->toBeTrue();
    });

    it('restarts the idle clock on a turn end the stop hook recorded', function (): void {
        $world = new WarmWorld;
        $keepWarm = $world->keepWarm(3300);

        $world->now += 3000;
        $world->turnEnds();

        $world->now += 3000;
        expect($keepWarm->due())->toBeFalse();

        $world->now += 300;
        expect($keepWarm->due())->toBeTrue();
    });

    it('stops keep-alives once the session has been idle past the ceiling', function (): void {
        $world = new WarmWorld;
        $keepWarm = $world->keepWarm(3300, 3 * 3300);

        $sent = 0;

        // An abandoned session: every keep-alive's own turn ends a few seconds after it is sent
        for ($minute = 0; $minute < 600; $minute++) {
            $world->now += 60;

            if ($keepWarm->due()) {
                $keepWarm->sent();
                $sent++;

                $world->now += 5;
                $world->turnEnds();
            }
        }

        expect($sent)->toBe(2);
    });

    it('stops at the ceiling itself, not a second after it', function (): void {
        $world = new WarmWorld;
        $keepWarm = $world->keepWarm(3300, 3300);

        $world->now += 3300;
        expect($keepWarm->due())->toBeFalse();
    });

    it('keeps sending without a ceiling', function (): void {
        $world = new WarmWorld;
        $keepWarm = $world->keepWarm(3300);

        $sent = 0;

        for ($minute = 0; $minute < 600; $minute++) {
            $world->now += 60;

            if ($keepWarm->due()) {
                $keepWarm->sent();
                $sent++;

                $world->now += 5;
                $world->turnEnds();
            }
        }

        expect($sent)->toBe(10);
    });

    it('takes only the first turn end after a keep-alive as its own', function (): void {
        $world = new WarmWorld;
        $keepWarm = $world->keepWarm(3300, 4000);

        $world->now += 3300;
        $keepWarm->sent();

        $world->now += 5;
        $world->turnEnds();
        expect($keepWarm->due())->toBeFalse();

        // A real turn a minute later is activity, so the ceiling is measured from it
        $world->now += 60;
        $world->turnEnds();

        $world->now += 3900;
        expect($keepWarm->due())->toBeTrue();
    });

    it('takes a turn end after a tool call as a real turn, even straight after a keep-alive', function (): void {
        $world = new WarmWorld;
        $keepWarm = $world->keepWarm(3300, 4000);

        $world->now += 3300;
        $keepWarm->sent();

        // The agent was woken and did call a tool, so the turn that ends is somebody's work
        $world->now += 10;
        $keepWarm->active();

        $world->now += 20;
        $world->turnEnds();

        // 4,005 seconds since the tool call and 3,985 since the turn end: only the second is inside
        // the ceiling, so this is due only if that turn end counted
        $world->now += 3985;
        expect($keepWarm->due())->toBeTrue();
    });

    it('takes a turn end long after a keep-alive as a real turn, since a dropped notice produces none', function (): void {
        $world = new WarmWorld;
        $keepWarm = $world->keepWarm(3300, 4000);

        $world->now += 3300;
        $keepWarm->sent();

        $world->now += KeepWarm::ABSORB_SECONDS + 1;
        $world->turnEnds();

        $world->now += 3900;
        expect($keepWarm->due())->toBeTrue();
    });
});

describe('the turn-end mark', function (): void {
    it('is written when the stop hook drains the sink', function (): void {
        $sink = new PendingEvents(WARM_SERVICE, 'claude', 'probe');

        expect($sink->turnEndedAt())->toBeNull();

        Artisan::call('pending', ['--project' => 'probe']);

        expect($sink->turnEndedAt())->toBeInt()
            ->and(abs(time() - (int) $sink->turnEndedAt()))->toBeLessThan(5);
    });

    it('is not written by a peek, which is somebody looking rather than a turn ending', function (): void {
        Artisan::call('pending', ['--project' => 'probe', '--peek' => true]);

        expect(new PendingEvents(WARM_SERVICE, 'claude', 'probe')->turnEndedAt())->toBeNull();
    });

    it('moves when a later turn ends', function (): void {
        $sink = new PendingEvents(WARM_SERVICE, 'claude', 'probe');

        $sink->markTurnEnded();
        touch($sink->turnMarkPath(), time() - 600);

        $sink->markTurnEnded();

        expect(abs(time() - (int) $sink->turnEndedAt()))->toBeLessThan(5);
    });

    it('reads a mark another process moved, rather than a cached one', function (): void {
        $sink = new PendingEvents(WARM_SERVICE, 'claude', 'probe');
        $sink->markTurnEnded();

        $moved = time() - 600;

        // **Started before the first read, and waited on without a process call between the reads.**
        // PHP caches the last `stat`, and measured on 8.4.23 an `exec()` clears that cache itself --
        // so a writer run with `exec()` between the reads passes with the clear removed. The stop
        // hook is exactly this: another process, moving the mark while the bridge sits between reads.
        $writer = proc_open(
            [PHP_BINARY, '-r', 'usleep(500000); touch($argv[1], (int) $argv[2]);', $sink->turnMarkPath(), (string) $moved],
            [],
            $pipes
        );

        if ($writer === false) {
            throw new RuntimeException('The writer process could not be started.');
        }

        $before = $sink->turnEndedAt();

        usleep(1_500_000);

        $after = $sink->turnEndedAt();

        proc_close($writer);

        expect($before)->not->toBe($moved)
            ->and($after)->toBe($moved);
    });

    it('is kept per bridge identity, like the sink', function (): void {
        new PendingEvents(WARM_SERVICE, 'claude', 'probe')->markTurnEnded();

        expect(new PendingEvents(WARM_SERVICE, 'claude', 'other')->turnEndedAt())->toBeNull();
    });

    it('is created narrow, beside the sink', function (): void {
        $sink = new PendingEvents(WARM_SERVICE, 'claude', 'probe');
        $sink->markTurnEnded();

        expect(\dirname($sink->turnMarkPath()))->toBe(\dirname($sink->path()))
            ->and(fileperms($sink->turnMarkPath()) & 0o777)->toBe(0o600);
    })->skipOnWindows();

    it('never fails the hook when it cannot be written', function (): void {
        // The state directory is a regular file, so nothing can be created beneath it
        file_put_contents($this->stateDirectory, '');

        try {
            $exit = Artisan::call('pending', ['--project' => 'probe']);

            expect($exit)->toBe(0);
        } finally {
            unlink($this->stateDirectory);
        }
    });
});

describe('the bridge', function (): void {
    it('sends one keep-alive once the session is idle past the interval', function (): void {
        $world = new WarmWorld;
        $world->now -= 3300;

        // Built in the past, so the interval has already passed by the time the bridge runs
        $keepWarm = $world->keepWarm(3300);
        $world->now += 3300;

        $notices = warmNotices(warmRun($keepWarm));

        expect($notices)->toHaveCount(1)
            ->and(data_get($notices[0], 'params.content'))->toBe(KeepWarm::NOTICE)
            ->and(data_get($notices[0], 'params.meta'))->toBe(['keep_warm' => '1']);
    });

    it('tells the agent what a keep-alive means, in the instructions it trusts', function (): void {
        $written = warmRun(new WarmWorld()->keepWarm());

        expect(data_get($written[0], 'result.instructions'))->toContain(Bridge::CHANNEL_INSTRUCTIONS.KeepWarm::INSTRUCTIONS);
    });

    it('sends nothing new, and says nothing new, with the option off', function (): void {
        $written = warmRun(null);

        expect(warmNotices($written))->toBeEmpty()
            ->and(data_get($written[0], 'result.instructions'))->not->toContain(KeepWarm::INSTRUCTIONS)
            ->and(data_get($written[0], 'result.instructions'))->toEndWith(Bridge::CHANNEL_INSTRUCTIONS);
    });

    it('sends no keep-alive to a session that is not idle', function (): void {
        expect(warmNotices(warmRun(new WarmWorld()->keepWarm(3300))))->toBeEmpty();
    });

    it('takes a tool call as activity', function (): void {
        $world = new WarmWorld;
        $world->now -= 3300;
        $keepWarm = $world->keepWarm(3300);
        $world->now += 3300;

        // The call arrives before the first pass, so that pass finds the session active
        $written = warmRun($keepWarm, extra: ['{"jsonrpc":"2.0","id":5,"method":"tools/call","params":{"name":"tasks","arguments":{}}}']);

        expect(warmNotices($written))->toBeEmpty();
    });

    it('sends nothing before the harness has said it finished initializing', function (): void {
        $world = new WarmWorld;
        $world->now -= 3300;
        $keepWarm = $world->keepWarm(3300);
        $world->now += 3300;

        expect(warmNotices(warmRun($keepWarm, initialized: false)))->toBeEmpty();
    });

    it('sends nothing for a harness that is not a channel', function (): void {
        $world = new WarmWorld;
        $world->now -= 3300;
        $keepWarm = $world->keepWarm(3300);
        $world->now += 3300;

        expect(warmNotices(warmRun($keepWarm, channel: false)))->toBeEmpty();
    });
});

describe('the options', function (): void {
    it('refuses what is not a whole number of minutes, and carries on without keep-alives', function (string $given): void {
        $output = new TwoStreamOutput;

        $exit = Artisan::call('mcp', ['--service' => WARM_SERVICE, '--keep-warm' => $given], $output);

        expect($exit)->toBe(0)
            ->and($output->stderr())->toContain('take a whole number of minutes')
            ->and($output->stdout())->toBeEmpty();
    })->with(['0', '-5', '55.5', '1e3', 'soon', '1234567']);

    it('refuses a ceiling that is not a whole number of minutes', function (): void {
        $output = new TwoStreamOutput;

        Artisan::call('mcp', ['--service' => WARM_SERVICE, '--keep-warm' => '55', '--keep-warm-for' => 'all night'], $output);

        expect($output->stderr())->toContain('take a whole number of minutes');
    });

    it('accepts whole minutes without a word', function (): void {
        $output = new TwoStreamOutput;

        Artisan::call('mcp', ['--service' => WARM_SERVICE, '--keep-warm' => '55', '--keep-warm-for' => '480'], $output);

        expect($output->stderr())->not->toContain('keep');
    });

    it('says a ceiling alone does nothing', function (): void {
        $output = new TwoStreamOutput;

        Artisan::call('mcp', ['--service' => WARM_SERVICE, '--keep-warm-for' => '480'], $output);

        expect($output->stderr())->toContain('--keep-warm-for applies only with --keep-warm');
    });

    it('is Claude Code only', function (): void {
        putenv('ROBOT_COUNCIL_HARNESS=cursor');

        $output = new TwoStreamOutput;

        Artisan::call('mcp', ['--service' => WARM_SERVICE, '--keep-warm' => '55'], $output);

        expect($output->stderr())->toContain('--keep-warm applies only to Claude Code');
    });
});
