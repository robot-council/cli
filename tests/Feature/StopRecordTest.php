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
use Carbon\CarbonInterval;
use Illuminate\Support\Sleep;
use Symfony\Component\Process\Process;
use Tests\Fixtures\FailingWriteStream;

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

it('writes no path for an anonymous class', function (): void {
    $anonymous = new class extends RuntimeException {};

    new StopRecord($this->path)->append(StopReason::Unhandled, ['exception' => $anonymous::class]);

    $raw = (string) file_get_contents($this->path);

    expect(stopLines($this->path)[0]['exception'])->toBe('RuntimeException')
        ->and($raw)->not->toContain('StopRecordTest')
        ->and($raw)->not->toContain('Users');
});

it('says why it wrote nothing when the path is a directory', function (): void {
    mkdir($this->path, 0777, true);

    $failure = new StopRecord($this->path)->append(StopReason::Other);

    // Named, because the next step fails differently if the open's failure is not returned
    expect($failure)->toBeString()->toContain('Could not open');

    rmdir($this->path);
});

it('drops blank and padded lines already in the file, and keeps the rest', function (): void {
    mkdir($this->directory);
    file_put_contents($this->path, "{\"old\":1}\n\n   \n{\"old\":2}\n");

    new StopRecord($this->path)->append(StopReason::Other, ['status' => 3]);

    $lines = stopLines($this->path);

    expect($lines)->toHaveCount(3)
        ->and($lines[0]['old'])->toBe(1)
        ->and($lines[2]['status'])->toBe(3);
});

it('starts again rather than read a file far past its bound', function (): void {
    mkdir($this->directory);
    file_put_contents($this->path, str_repeat("{\"old\":1}\n", 600_000));

    $failure = new StopRecord($this->path)->append(StopReason::Other, ['status' => 7]);

    expect($failure)->toBeNull()
        ->and(stopLines($this->path))->toHaveCount(1);
});

it('says it could not lock when another holder keeps the file', function (): void {
    mkdir($this->directory);
    file_put_contents($this->path, '');

    $holder = fopen($this->path, 'c+');

    expect($holder)->toBeResource();

    if (! \is_resource($holder)) {
        return;
    }

    flock($holder, LOCK_EX);

    Sleep::fake();

    $failure = new StopRecord($this->path)->append(StopReason::Other);

    // Tried for as long as it says: one wait after each of the 40 tries
    Sleep::assertSleptTimes(40);

    flock($holder, LOCK_UN);
    fclose($holder);
    Sleep::fake(false);

    expect($failure)->toBeString()->toContain('Could not lock')
        ->and((string) file_get_contents($this->path))->toBeEmpty();
});

it('returns a throwable the write raises as a reason, and does not throw', function (): void {
    // A path with a NUL byte makes PHP raise a `ValueError` rather than return false
    $failure = new StopRecord($this->directory."/a\0b.jsonl")->append(StopReason::Other);

    expect($failure)->toBe('Could not record why this bridge stopped (ValueError).');
});

it('says why when a write is refused, and not that the line is in the file', function (): void {
    stream_wrapper_register('failwrite', FailingWriteStream::class);

    try {
        $failure = new StopRecord('failwrite://dir/stops.jsonl')->append(StopReason::Other);
    } finally {
        stream_wrapper_unregister('failwrite');
    }

    expect($failure)->toBe('Could not write `failwrite://dir/stops.jsonl` to record why this bridge stopped.');
});

it('pins the bound the file keeps', function (): void {
    // A literal, because a test that compares against the constant passes whatever it is changed to
    expect(StopRecord::KEEP)->toBe(1000);
});

it('writes every fact it allows, each under its own name', function (): void {
    new StopRecord($this->path)->append(StopReason::Signal, [
        'parent' => 11,
        'session' => 22,
        'version' => 'v1.2.3',
        'reader' => 'exited-0',
        'status' => 409,
        'signal' => 'SIGTERM',
        'exception' => 'LogicException',
    ]);

    $line = stopLines($this->path)[0];

    expect($line['parent'])->toBe(11)
        ->and($line['session'])->toBe(22)
        ->and($line['version'])->toBe('v1.2.3')
        ->and($line['reader'])->toBe('exited-0')
        ->and($line['status'])->toBe(409)
        ->and($line['signal'])->toBe('SIGTERM')
        ->and($line['exception'])->toBe('LogicException');
});

it('reads a file of exactly the read limit back, and replaces one a byte larger', function (int $extraBytes, int $expectedLines): void {
    mkdir($this->directory);

    // 8 bytes a line, so 524,288 lines is exactly 4,194,304 bytes; an extra blank line adds one byte
    file_put_contents($this->path, str_repeat("{\"o\":1}\n", 524_288).str_repeat("\n", $extraBytes));

    $failure = new StopRecord($this->path)->append(StopReason::Other);

    expect($failure)->toBeNull()
        ->and(stopLines($this->path))->toHaveCount($expectedLines);
})->with([
    'at the limit is read, and the newest 1,000 kept' => [0, 1000],
    'one byte over is replaced' => [1, 1],
]);

it('waits fifty milliseconds between lock attempts', function (): void {
    mkdir($this->directory);
    file_put_contents($this->path, '');

    $holder = fopen($this->path, 'c+');

    expect($holder)->toBeResource();

    if (! \is_resource($holder)) {
        return;
    }

    flock($holder, LOCK_EX);

    Sleep::fake();

    new StopRecord($this->path)->append(StopReason::Other);

    Sleep::assertSlept(fn (CarbonInterval $duration): bool => (int) $duration->totalMilliseconds === 50, 40);

    flock($holder, LOCK_UN);
    fclose($holder);
    Sleep::fake(false);
});
