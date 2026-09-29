<?php

declare(strict_types=1);

/**
 * The bridge records its resolved sink key, and the stop hook reads that record (cli#346).
 *
 * The bridge and its hook each resolved the checkout with `git`, and a lookup that answered one and
 * timed out for the other put them on two sinks with nothing to say so. Now the bridge resolves it
 * once at start and records the key, and every other reader of the sink uses the record, resolving
 * on its own only where there is none.
 *
 * `git` is faked through `Process`, which is how `Checkout` runs it: an answer, a timeout, or
 * nothing to say. The record is named from what both sides know without git, so the fake decides
 * only what a lookup answers.
 *
 * @command  vendor/bin/pest --compact tests/Feature/RecordedKeyTest.php
 */

use App\Support\PendingEvents;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyTimedOut;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\Fixtures\TwoStreamOutput;

const RECORDED_SERVICE = 'https://recorded.example.test';

/**
 * Fake `git rev-parse` answering with a git directory, or running out of time.
 */
function recordedGit(?string $gitDirectory): void
{
    Process::fake(function () use ($gitDirectory): mixed {
        if ($gitDirectory !== null) {
            return Process::result(output: $gitDirectory."\n");
        }

        $git = new SymfonyProcess(['git']);

        throw new ProcessTimedOutException(new SymfonyTimedOut($git, SymfonyTimedOut::TYPE_GENERAL), new ProcessResult($git));
    });
}

/**
 * The sink for this test's checkout, as the bridge or the hook builds it.
 */
function recordedSink(string $checkout, bool $useRecordedKey = true, string $harness = 'claude', ?string $project = null): PendingEvents
{
    return new PendingEvents(RECORDED_SERVICE, $harness, $project, $checkout, $useRecordedKey);
}

/**
 * A directive as the feed serializes it.
 *
 * @return array<string, mixed>
 */
function recordedEvent(): array
{
    return [
        'id' => 5,
        'type' => 'directive',
        'body' => 'rebase your branch',
        'meta' => [],
        'created_at' => '2026-09-29T00:00:00+00:00',
        'actor' => ['session_id' => 9, 'github_login' => 'otherdev', 'coordinator_direct' => true],
    ];
}

/**
 * Forget every checkout this process resolved, as a separate process starts out.
 *
 * `PendingEvents` keeps a definite answer for the life of a process. The bridge and the hook are
 * two processes, so a hook run in this one must not inherit the bridge's answer, or a test passes on
 * that memory rather than on the record.
 */
function recordedNewProcess(): void
{
    $class = new ReflectionClass(PendingEvents::class);
    $class->setStaticPropertyValue('checkouts', []);
    $class->setStaticPropertyValue('unresolved', []);
}

/**
 * Run the stop hook's `pending` for this checkout, as a new process, and return its exit code and stdout.
 *
 * @return array{0: int, 1: string}
 */
function recordedHook(string $checkout): array
{
    recordedNewProcess();
    putenv('CLAUDE_PROJECT_DIR='.$checkout);

    $output = new TwoStreamOutput;
    $exit = Artisan::call('pending', ['--service' => RECORDED_SERVICE, '--harness' => 'claude'], $output);

    return [$exit, $output->stdout()];
}

/**
 * The record files in the state directory.
 *
 * @return list<string>
 */
function recordedFiles(string $state): array
{
    return glob($state.'/robot-council/pending/*.key') ?: [];
}

beforeEach(function (): void {
    $this->state = sys_get_temp_dir().'/rc-recorded-'.bin2hex(random_bytes(6));
    putenv('XDG_STATE_HOME='.$this->state);

    // A fresh directory per test, so no resolution from another test is remembered for it
    $this->checkout = $this->state.'/checkout-'.bin2hex(random_bytes(4));
    File::ensureDirectoryExists($this->checkout);

    $this->projectDir = getenv('CLAUDE_PROJECT_DIR');
});

afterEach(function (): void {
    putenv('XDG_STATE_HOME');
    putenv(is_string($this->projectDir) ? 'CLAUDE_PROJECT_DIR='.$this->projectDir : 'CLAUDE_PROJECT_DIR');

    File::deleteDirectory($this->state);
});

it("delivers from the bridge's sink when the hook's own git lookup times out", function (): void {
    // The bridge: git answers, it records the key, and its follower writes an event
    recordedGit($this->checkout.'/.git');
    $bridge = recordedSink($this->checkout);
    $bridge->recordKey();
    $bridge->add([recordedEvent()]);

    // The hook, a turn later, on a machine where git no longer answers in time
    recordedGit(null);

    [$exit, $stdout] = recordedHook($this->checkout);

    expect($exit)->toBe(0)
        ->and($stdout)->toContain('rebase your branch');
});

it('refuses, rather than guessing, when there is no record and the lookup times out', function (): void {
    // The control for the test above: the same timeout with no record is what the record replaces,
    // and #345 refuses it rather than reading some other sink
    recordedGit($this->checkout.'/.git');
    recordedSink($this->checkout, useRecordedKey: false)->add([recordedEvent()]);

    recordedGit(null);

    expect(recordedHook($this->checkout)[0])->toBe(3);
});

it('keeps every reader on the sink the bridge recorded, even after git starts answering', function (): void {
    // The bridge started while git timed out, so it recorded the fallback key
    recordedGit(null);
    recordedSink($this->checkout)->recordKey();

    // Later, git answers: the follower and the hook would each have resolved the git directory
    recordedGit($this->checkout.'/.git');
    $follower = recordedSink($this->checkout);
    $follower->add([recordedEvent()]);

    // The control: resolved afresh, the same checkout now keys a different sink
    expect(recordedSink($this->checkout, useRecordedKey: false)->path())->not->toBe($follower->path());

    [$exit, $stdout] = recordedHook($this->checkout);

    expect($exit)->toBe(0)
        ->and($stdout)->toContain('rebase your branch');
});

it('resolves on its own where the bridge left no record, or none that reads as a key', function (?string $contents): void {
    recordedGit($this->checkout.'/.git');
    recordedSink($this->checkout, useRecordedKey: false)->add([recordedEvent()]);

    if ($contents !== null) {
        recordedSink($this->checkout)->recordKey();
        File::put(recordedFiles($this->state)[0], $contents);
    }

    // Git answers, so resolving finds the same sink the bridge wrote: as before this change
    [$exit, $stdout] = recordedHook($this->checkout);

    expect($exit)->toBe(0)
        ->and($stdout)->toContain('rebase your branch');
})->with([
    'no record' => [null],
    'a record that is not a key' => ['not-a-key'],
]);

it('records nothing for a sink whose key does not depend on the checkout', function (string $harness, ?string $project): void {
    recordedGit($this->checkout.'/.git');

    recordedSink($this->checkout, harness: $harness, project: $project)->recordKey();

    expect(recordedFiles($this->state))->toBeEmpty();

    // The control: the same call for a checkout-keyed sink does record
    recordedSink($this->checkout)->recordKey();

    expect(recordedFiles($this->state))->toHaveCount(1);
})->with([
    'a named project' => ['claude', 'org/repo'],
    'Cursor, one sink per application' => ['cursor', null],
]);

it('records the key the bridge uses, at start', function (): void {
    recordedGit($this->checkout.'/.git');
    putenv('ROBOT_COUNCIL_HARNESS=claude');
    putenv('CLAUDE_CONFIG_DIR='.$this->state.'/claude');

    $cwd = getcwd();
    chdir($this->checkout);

    try {
        Artisan::call('mcp', ['--service' => RECORDED_SERVICE], new TwoStreamOutput);
    } finally {
        chdir((string) $cwd);
        putenv('ROBOT_COUNCIL_HARNESS');
        putenv('CLAUDE_CONFIG_DIR');
    }

    $files = recordedFiles($this->state);

    expect($files)->toHaveCount(1)
        ->and(trim((string) file_get_contents($files[0])))->toBe(basename(recordedSink($this->checkout, useRecordedKey: false)->path(), '.json'));
})->skipOnWindows();
