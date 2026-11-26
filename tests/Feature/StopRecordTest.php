<?php

declare(strict_types=1);

/**
 * The line a bridge leaves when it stops, and what may and may not be in it (#396).
 *
 * **Measured on josh-home on 2026-11-26 (#362):** 42 bridges stopped with their parents alive, the
 * service recorded each as `ended`, and nothing on the machine said which end of the loop ran. These
 * tests hold the file that now does: one line per stop, from a closed list of reasons, with nothing
 * in it that could carry a credential.
 *
 * @command  vendor/bin/pest --compact tests/Feature/StopRecordTest.php
 */

use App\Support\PendingEvents;
use App\Support\StopReason;
use App\Support\StopRecord;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    $this->directory = sys_get_temp_dir().'/rc-stops-'.bin2hex(random_bytes(6));
    $this->path = $this->directory.'/stops.jsonl';
});

afterEach(function (): void {
    array_map(unlink(...), glob($this->directory.'/*') ?: []);
    @rmdir($this->directory);
});

/**
 * What the file holds, one decoded line each.
 *
 * @return list<array<array-key, mixed>>
 */
function stopLines(string $path): array
{
    $contents = file_get_contents($path);

    expect($contents)->toBeString();

    $lines = [];

    foreach (explode("\n", (string) $contents) as $line) {
        if ($line === '') {
            continue;
        }

        $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);

        // A line that is not an object is a failure to report, not one to skip
        expect($decoded)->toBeArray();

        if (\is_array($decoded)) {
            $lines[] = $decoded;
        }
    }

    return $lines;
}

it('writes one line with the time, the pid, the reason and the facts it was given', function (): void {
    $failure = new StopRecord($this->path)->append(StopReason::SessionGone, ['status' => 409, 'session' => 42, 'version' => 'v0.4.33']);

    $lines = stopLines($this->path);

    expect($failure)->toBeNull()
        ->and($lines)->toHaveCount(1)
        ->and($lines[0]['reason'])->toBe('session-gone')
        ->and($lines[0]['pid'])->toBe(getmypid())
        ->and($lines[0]['status'])->toBe(409)
        ->and($lines[0]['session'])->toBe(42)
        ->and($lines[0]['version'])->toBe('v0.4.33')
        ->and($lines[0]['at'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/');
});

it('leaves out a fact that is null, and writes nothing it was not allowed to', function (): void {
    // The planted-secret control: a message, a URL and a header are exactly what must never arrive,
    // and the record has to refuse them by name rather than by the caller remembering not to send them
    $secret = 'rcouncil_2|SECRET-TOKEN-VALUE';

    new StopRecord($this->path)->append(StopReason::Unhandled, [
        'exception' => 'RuntimeException',
        'parent' => null,
        'message' => 'The call failed: '.$secret,
        'url' => 'https://fleet.example.test/api/sessions?token='.$secret,
        'authorization' => 'Bearer '.$secret,
    ]);

    $raw = (string) file_get_contents($this->path);
    $line = stopLines($this->path)[0];

    expect($line['exception'])->toBe('RuntimeException')
        ->and($line)->not->toHaveKey('parent')
        ->and($line)->not->toHaveKey('message')
        ->and($line)->not->toHaveKey('url')
        ->and($line)->not->toHaveKey('authorization')
        ->and($raw)->not->toContain('SECRET-TOKEN-VALUE')
        ->and($raw)->not->toContain('Bearer');
});

it('reduces an allowed fact to a plain alphabet and cuts it short', function (): void {
    new StopRecord($this->path)->append(StopReason::Unhandled, [
        // Spaces, slashes, quotes and a newline cannot get into a line through a fact
        'exception' => "App\\Support\\Thing: a \"quoted\" /path?x=1\nand more",
        'reader' => str_repeat('a', 500),
    ]);

    $line = stopLines($this->path)[0];

    expect($line['exception'])->toBe('App\\Support\\Thing:aquotedpathx1andmore')
        ->and($line['reader'])->toBeString()->toHaveLength(120);
});

it('keeps the newest lines and no more than the bound', function (): void {
    $record = new StopRecord($this->path);

    for ($number = 1; $number <= StopRecord::KEEP + 5; $number++) {
        $record->append(StopReason::Other, ['status' => $number]);
    }

    $lines = stopLines($this->path);

    expect($lines)->toHaveCount(StopRecord::KEEP)
        ->and($lines[0]['status'])->toBe(6)
        ->and($lines[StopRecord::KEEP - 1]['status'])->toBe(StopRecord::KEEP + 5);
});

it('says why it wrote nothing when it cannot, and does not throw', function (): void {
    // A file where a directory is needed, so the directory cannot be made
    mkdir($this->directory);
    file_put_contents($this->directory.'/blocker', 'x');

    $failure = new StopRecord($this->directory.'/blocker/stops.jsonl')->append(StopReason::Other);

    expect($failure)->toBeString()->toContain('Could not')
        ->and(file_exists($this->directory.'/blocker/stops.jsonl'))->toBeFalse();
});

it('keeps every line intact when many bridges stop at once', function (): void {
    $count = 25;
    $script = __DIR__.'/../Fixtures/append-stop-record.php';

    // Started together and only then waited for, so the appends overlap rather than queue
    $processes = array_map(function (int $number) use ($script): Process {
        $process = new Process([PHP_BINARY, $script, $this->path, (string) $number], null, null, null, 60);
        $process->start();

        return $process;
    }, range(1, $count));

    foreach ($processes as $process) {
        $process->wait();
    }

    $exits = array_map(static fn (Process $process): ?int => $process->getExitCode(), $processes);
    $statuses = array_column(stopLines($this->path), 'status');
    sort($statuses);

    expect($exits)->each->toBe(0)
        ->and($statuses)->toBe(range(1, $count));
});

it('sits beside the sinks, in the directory they share', function (): void {
    putenv('XDG_STATE_HOME='.$this->directory);

    try {
        expect(StopRecord::atDefaultPath()->path())->toBe($this->directory.'/robot-council/stops.jsonl')
            ->and(PendingEvents::stateDirectory())->toBe($this->directory.'/robot-council');
    } finally {
        putenv('XDG_STATE_HOME');
    }
});

it('records a throwable that escaped over whatever the bridge noted, and other when nothing was noted', function (): void {
    expect(StopReason::recordedFor('LogicException', StopReason::SessionGone))->toBe(StopReason::Unhandled)
        ->and(StopReason::recordedFor('LogicException', null))->toBe(StopReason::Unhandled)
        ->and(StopReason::recordedFor(null, StopReason::Signal))->toBe(StopReason::Signal)
        ->and(StopReason::recordedFor(null, null))->toBe(StopReason::Other);
});
