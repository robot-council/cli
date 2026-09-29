<?php

declare(strict_types=1);

/**
 * A sink `pending` could not read says so, rather than answering "nothing waiting" (cli#345).
 *
 * Measured on a saturated machine: `pending --peek` printed nothing and exited 0 while the sink held
 * an event. Each way a read can fail here -- a lock held past the wait, contents that do not parse,
 * a checkout whose git directory would not resolve -- now exits `PendingCommand::UNREADABLE` with
 * the reason on stderr, and removes nothing. Each has a control on a genuinely empty sink, which
 * stays silent with exit 0, because a stop hook ends the turn on exactly that.
 *
 * @command  vendor/bin/pest --compact tests/Feature/UnreadableSinkTest.php
 */

use App\Commands\PendingCommand;
use App\Support\PendingEvents;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyTimedOut;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\Fixtures\TwoStreamOutput;

const UNREADABLE_SERVICE = 'https://unreadable.example.test';

/**
 * The sink these tests read, named by project so no checkout lookup is involved.
 */
function unreadableSink(): PendingEvents
{
    return new PendingEvents(UNREADABLE_SERVICE, 'claude', 'unreadable');
}

/**
 * Run `pending` for this sink, and return its exit code, stdout and stderr.
 *
 * @param  bool  $peek  Whether to peek rather than deliver.
 * @return array{0: int, 1: string, 2: string}
 */
function unreadableRun(bool $peek = false): array
{
    $output = new TwoStreamOutput;

    $exit = Artisan::call('pending', ['--service' => UNREADABLE_SERVICE, '--harness' => 'claude', '--project' => 'unreadable', ...($peek ? ['--peek' => true] : [])], $output);

    return [$exit, $output->stdout(), $output->stderr()];
}

/**
 * A directive as the feed serializes it.
 *
 * @return array<string, mixed>
 */
function unreadableEvent(): array
{
    return [
        'id' => 7,
        'type' => 'directive',
        'body' => 'rebase your branch',
        'meta' => [],
        'created_at' => '2026-09-29T00:00:00+00:00',
        'actor' => ['session_id' => 9, 'github_login' => 'otherdev', 'coordinator_direct' => true],
    ];
}

/**
 * Fake `git` running out of time, as `Process` reports it.
 */
function unreadableGitTimesOut(): void
{
    Process::fake(function (): never {
        $git = new SymfonyProcess(['git']);

        throw new ProcessTimedOutException(new SymfonyTimedOut($git, SymfonyTimedOut::TYPE_GENERAL), new ProcessResult($git));
    });
}

beforeEach(function (): void {
    $this->state = sys_get_temp_dir().'/rc-unreadable-'.bin2hex(random_bytes(6));
    putenv('XDG_STATE_HOME='.$this->state);

    // Restored afterwards, since Claude Code sets it for a suite run inside it
    $this->projectDir = getenv('CLAUDE_PROJECT_DIR');
});

afterEach(function (): void {
    putenv('XDG_STATE_HOME');
    putenv(is_string($this->projectDir) ? 'CLAUDE_PROJECT_DIR='.$this->projectDir : 'CLAUDE_PROJECT_DIR');

    File::deleteDirectory($this->state);
});

it('stays silent with exit 0 on a genuinely empty sink', function (bool $peek, ?string $contents): void {
    if ($contents !== null) {
        File::ensureDirectoryExists(dirname(unreadableSink()->path()));
        File::put(unreadableSink()->path(), $contents);
    }

    // The controls for every test below: nothing here is a failure, and nothing is said
    expect(unreadableRun($peek))->toBe([0, '', '']);
})->with([
    'no sink yet, peek' => [true, null],
    'no sink yet, deliver' => [false, null],
    'an emptied sink, peek' => [true, ''],
    'an emptied sink, deliver' => [false, ''],
]);

it('says so and exits UNREADABLE when the sink lock is held past the wait, removing nothing', function (bool $peek): void {
    unreadableSink()->add([unreadableEvent()]);
    $before = (string) file_get_contents(unreadableSink()->path());

    // The wait counts attempts rather than time, so a faked sleep runs the same schedule at once
    Sleep::fake();

    // Another process's writer, in effect: a second handle's lock refuses this one
    $holder = fopen(unreadableSink()->path(), 'r+');
    expect($holder !== false && flock($holder, LOCK_EX))->toBeTrue();

    try {
        [$exit, $stdout, $stderr] = unreadableRun($peek);
    } finally {
        if (is_resource($holder)) {
            flock($holder, LOCK_UN);
            fclose($holder);
        }
    }

    expect($exit)->toBe(PendingCommand::UNREADABLE)
        ->and($stdout)->toBeEmpty()
        ->and($stderr)->toContain('robot-council: Could not lock the sink')
        ->and((string) file_get_contents(unreadableSink()->path()))->toBe($before);

    // Released, the same sink reads: the event was waiting all along
    expect(unreadableRun(true)[1])->toContain('rebase your branch');
})->with([
    'peek' => [true],
    'deliver' => [false],
]);

it('says so and exits UNREADABLE when the sink does not parse, and leaves it as it was', function (bool $peek, string $contents, string $says): void {
    File::ensureDirectoryExists(dirname(unreadableSink()->path()));
    File::put(unreadableSink()->path(), $contents);

    [$exit, $stdout, $stderr] = unreadableRun($peek);

    expect($exit)->toBe(PendingCommand::UNREADABLE)
        ->and($stdout)->toBeEmpty()
        ->and($stderr)->toStartWith('robot-council: ')
        ->and($stderr)->toContain($says)

        // Byte for byte: the lenient read decoded nothing and wrote that nothing back over it
        ->and((string) file_get_contents(unreadableSink()->path()))->toBe($contents);
})->with([
    'truncated JSON, peek' => [true, '[{"id":7,"type":"directive"', 'does not parse: Syntax error. It was left as it was; move it aside'],
    'truncated JSON, deliver' => [false, '[{"id":7,"type":"directive"', 'does not parse'],
    'an object, not a list, deliver' => [false, '{"id":7}', 'is not a list of events'],
]);

it('says so and exits UNREADABLE when the checkout it keys by timed out', function (bool $peek): void {
    // No project named, so the key depends on the checkout, and `git` ran out of time every attempt
    unreadableGitTimesOut();
    putenv('CLAUDE_PROJECT_DIR='.$this->state.'/checkout-'.bin2hex(random_bytes(4)));

    $output = new TwoStreamOutput;
    $exit = Artisan::call('pending', ['--service' => UNREADABLE_SERVICE, '--harness' => 'claude', ...($peek ? ['--peek' => true] : [])], $output);

    expect($exit)->toBe(PendingCommand::UNREADABLE)
        ->and($output->stdout())->toBeEmpty()
        ->and($output->stderr())->toContain('robot-council: Could not tell which checkout this is');
})->with(['peek' => true, 'deliver' => false]);

it('reads on when git failed some other way, which falls back the same way in the bridge', function (int $exitCode): void {
    // The controls for the test above. A missing or broken git (1), or a folder that is not a
    // repository (128), answers the same way in every process, so the bridge and the hook agree on
    // the fallback key and the read goes ahead: refusing would block a working seat on every turn
    Process::fake(['*' => Process::result(exitCode: $exitCode)]);
    putenv('CLAUDE_PROJECT_DIR='.$this->state.'/checkout-'.bin2hex(random_bytes(4)));

    $output = new TwoStreamOutput;

    expect(Artisan::call('pending', ['--service' => UNREADABLE_SERVICE, '--harness' => 'claude', '--peek' => true], $output))->toBe(0)
        ->and($output->stderr())->toBeEmpty();
})->with(['git failed' => 1, 'not a repository' => 128]);

it('does not refuse a named project because some checkout timed out', function (): void {
    unreadableGitTimesOut();
    putenv('CLAUDE_PROJECT_DIR='.$this->state.'/checkout-'.bin2hex(random_bytes(4)));

    // The checkout's lookup times out and is remembered as such, in this process
    expect(Artisan::call('pending', ['--service' => UNREADABLE_SERVICE, '--harness' => 'claude', '--peek' => true], new TwoStreamOutput))->toBe(PendingCommand::UNREADABLE);

    // A named project's key never depends on the checkout, so that is nothing to it
    expect(unreadableRun(true))->toBe([0, '', '']);
});
