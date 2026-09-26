<?php

declare(strict_types=1);

/**
 * One seat per checkout, enforced by a warning (#320).
 *
 * **Real processes where the claim is about processes.** Whether the operating system releases a
 * killed bridge's lock can only be shown by killing a process that holds one, and what a second
 * bridge says can only be shown by starting one.
 *
 * @command  vendor/bin/pest --compact tests/Feature/SeatTest.php
 */

use App\Support\Bridge;
use App\Support\PendingEvents;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

const SEAT_SERVICE = 'https://fleet.example.test';

/**
 * A process that takes a sink's seat and holds it until its stdin closes or it is killed.
 */
function seatHolderProcess(string $state, string $checkout, ?string $project = null): Process
{
    $holder = new Process(
        [PHP_BINARY, '-r', 'require $argv[1]; $s = new App\Support\PendingEvents($argv[2], "claude", $argv[4] === "" ? null : $argv[4], $argv[3]); echo $s->claimSeat() ? "held\n" : "refused\n"; fgets(STDIN);',
            base_path('vendor/autoload.php'), SEAT_SERVICE, $checkout, $project ?? ''],
        null,
        ['XDG_STATE_HOME' => $state],
    );

    $holder->setInput(new InputStream);
    $holder->start();

    // Until it has answered, so the test does not race its claim
    $holder->waitUntil(fn (string $type, string $output): bool => str_contains($output, 'held') || str_contains($output, 'refused'));

    return $holder;
}

/**
 * A value that must be a string, or the test fails saying what it was.
 */
function seatText(mixed $value): string
{
    if (! is_string($value)) {
        throw new RuntimeException(sprintf('Expected a string, got %s.', get_debug_type($value)));
    }

    return $value;
}

beforeEach(function (): void {
    $this->root = str_replace('\\', '/', sys_get_temp_dir()).'/rc-seat-'.bin2hex(random_bytes(6));
    $this->state = $this->root.'/state';

    File::ensureDirectoryExists($this->root.'/one');
    File::ensureDirectoryExists($this->root.'/two');

    putenv('XDG_STATE_HOME='.$this->state);
});

afterEach(function (): void {
    putenv('XDG_STATE_HOME');

    File::deleteDirectory($this->root);
});

it('takes a free seat and records its own pid', function (): void {
    $seat = new PendingEvents(SEAT_SERVICE, 'claude', null, $this->root.'/one');

    expect($seat->claimSeat())->toBeTrue()
        ->and($seat->seatHolder())->toBe(getmypid());
});

it('answers again that it holds a seat it already holds, rather than conflicting with itself', function (): void {
    $seat = new PendingEvents(SEAT_SERVICE, 'claude', null, $this->root.'/one');

    expect($seat->claimSeat())->toBeTrue()
        ->and($seat->claimSeat())->toBeTrue();
});

it('refuses a held seat to a second bridge in the same checkout, and names the holder', function (): void {
    $first = new PendingEvents(SEAT_SERVICE, 'claude', null, $this->root.'/one');
    $second = new PendingEvents(SEAT_SERVICE, 'claude', null, $this->root.'/one');

    expect($first->claimSeat())->toBeTrue()
        ->and($second->claimSeat())->toBeFalse()
        ->and($second->seatHolder())->toBe(getmypid());
});

it('gives each checkout its own seat', function (): void {
    // Held in a variable: the lock lives as long as the object holding it, as it does in `mcp`
    $one = new PendingEvents(SEAT_SERVICE, 'claude', null, $this->root.'/one');

    expect($one->claimSeat())->toBeTrue()
        ->and(new PendingEvents(SEAT_SERVICE, 'claude', null, $this->root.'/two')->claimSeat())->toBeTrue()
        ->and(new PendingEvents(SEAT_SERVICE, 'claude', null, $this->root.'/one')->claimSeat())->toBeFalse();
});

it('makes two checkouts deliberately sharing a project share a seat', function (): void {
    $one = new PendingEvents(SEAT_SERVICE, 'claude', 'org/repo', $this->root.'/one');

    expect($one->claimSeat())->toBeTrue()
        ->and(new PendingEvents(SEAT_SERVICE, 'claude', 'org/repo', $this->root.'/two')->claimSeat())->toBeFalse();
});

it('frees the seat when the object holding it goes, as a bridge process ending does', function (): void {
    $one = new PendingEvents(SEAT_SERVICE, 'claude', null, $this->root.'/one');
    expect($one->claimSeat())->toBeTrue();

    unset($one);

    expect(new PendingEvents(SEAT_SERVICE, 'claude', null, $this->root.'/one')->claimSeat())->toBeTrue();
});

it('frees the seat when the bridge holding it is killed, leaving no stale lock', function (): void {
    $holder = seatHolderProcess($this->state, $this->root.'/one');

    expect($holder->getOutput())->toContain('held')
        ->and(new PendingEvents(SEAT_SERVICE, 'claude', null, $this->root.'/one')->claimSeat())->toBeFalse();

    // Killed, not asked: no shutdown code runs, so only the operating system can release it
    \defined('SIGKILL') ? $holder->signal(SIGKILL) : $holder->stop(0);
    $holder->wait();

    expect(new PendingEvents(SEAT_SERVICE, 'claude', null, $this->root.'/one')->claimSeat())->toBeTrue();
});

it('frees the seat when its holder is killed, even though a child it started lives on', function (): void {
    // The bridge's own shape: a long-lived child (the stdin reader) outlives a killed parent. A child
    // holding a copy of the lock kept the seat held, so the next bridge warned about a dead one
    $holder = new Process(
        [PHP_BINARY, '-r', 'require $argv[1]; $s = new App\\Support\\PendingEvents($argv[2], "claude", null, $argv[3]); $ok = $s->claimSeat(); $c = proc_open([PHP_BINARY, "-r", "sleep(30);"], [], $pipes, null, null, ["bypass_shell" => true]); echo ($ok ? "held " : "refused ").proc_get_status($c)["pid"]."\n"; fgets(STDIN);',
            base_path('vendor/autoload.php'), SEAT_SERVICE, $this->root.'/one'],
        null,
        ['XDG_STATE_HOME' => $this->state],
    );
    $holder->setInput(new InputStream);
    $holder->start();
    $holder->waitUntil(fn (string $type, string $output): bool => str_contains($output, "\n"));

    $child = (int) trim(substr($holder->getOutput(), (int) strpos($holder->getOutput(), ' ') + 1));

    try {
        expect($holder->getOutput())->toStartWith('held')
            ->and(posix_kill($child, 0))->toBeTrue();

        $holder->signal(SIGKILL);
        $holder->wait();

        // The child is still alive, and the seat is free anyway
        expect(posix_kill($child, 0))->toBeTrue()
            ->and(new PendingEvents(SEAT_SERVICE, 'claude', null, $this->root.'/one')->claimSeat())->toBeTrue();
    } finally {
        posix_kill($child, SIGKILL);
    }
})->skip(PHP_OS_FAMILY === 'Windows' || ! function_exists('posix_kill'), 'Close-on-exec is POSIX; on Windows `mcp` takes the seat after starting its long-lived child.');

it('warns a second bridge started in a held checkout, on stderr and in what the agent reads', function (): void {
    // This test's own process holds the seat of the checkout the bridge starts in
    $seat = new PendingEvents(SEAT_SERVICE, 'claude', null, $this->root.'/one');
    expect($seat->claimSeat())->toBeTrue();

    $bridge = new Process(
        [PHP_BINARY, base_path('robot-council'), 'mcp', '--service='.SEAT_SERVICE],
        $this->root.'/one',
        ['XDG_STATE_HOME' => $this->state, 'ROBOT_COUNCIL_HARNESS' => 'claude', 'CLAUDE_CONFIG_DIR' => $this->root.'/claude', 'ROBOT_COUNCIL_SERVICE' => false],
        '{"jsonrpc":"2.0","id":0,"method":"initialize","params":{"protocolVersion":"2025-11-25"}}'."\n",
        60,
    );
    $bridge->run();

    $warning = sprintf(Bridge::SHARED_SINK_WARNING, 'pid '.getmypid());
    $first = strtok($bridge->getOutput(), "\n");

    expect($bridge->getErrorOutput())->toContain($warning)
        ->and(seatText(data_get(json_decode($first === false ? '' : $first, true), 'result.instructions')))->toContain($warning);
});

it('says nothing when a bridge starts in a free checkout', function (): void {
    $bridge = new Process(
        [PHP_BINARY, base_path('robot-council'), 'mcp', '--service='.SEAT_SERVICE],
        $this->root.'/two',
        ['XDG_STATE_HOME' => $this->state, 'ROBOT_COUNCIL_HARNESS' => 'claude', 'CLAUDE_CONFIG_DIR' => $this->root.'/claude', 'ROBOT_COUNCIL_SERVICE' => false],
        '{"jsonrpc":"2.0","id":0,"method":"initialize","params":{"protocolVersion":"2025-11-25"}}'."\n",
        60,
    );
    $bridge->run();

    expect($bridge->getErrorOutput())->not->toContain('another bridge is running');
});
