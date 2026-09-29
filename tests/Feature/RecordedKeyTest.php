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
use App\Support\Credentials\Credential;
use App\Support\Credentials\Credentials;
use App\Support\PendingEvents;
use Illuminate\Http\Client\Request;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\PendingProcess;
use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyTimedOut;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\Fixtures\RecordingStore;
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

it('records the key while the bridge runs, lets a timed-out hook read it, and removes it on the way out', function (): void {
    // A real bridge, as its own process, with stdin held open so it keeps running
    $bridge = new SymfonyProcess(
        [PHP_BINARY, base_path('robot-council'), 'mcp', '--service='.RECORDED_SERVICE],
        $this->checkout,
        ['XDG_STATE_HOME' => $this->state, 'ROBOT_COUNCIL_HARNESS' => 'claude', 'CLAUDE_CONFIG_DIR' => $this->state.'/claude', 'ROBOT_COUNCIL_SERVICE' => false],
    );
    $bridge->setInput($input = new InputStream);
    $bridge->start();

    // Until its record exists: it is written before the bridge reads anything
    for ($waited = 0; $waited < 300 && recordedFiles($this->state) === [] && $bridge->isRunning(); $waited++) {
        usleep(100_000);
    }

    $files = recordedFiles($this->state);

    try {
        // The checkout is not a repository, so git's answer is definite and the key is the folder's
        expect($files)->toHaveCount(1)
            ->and(trim((string) file_get_contents($files[0])))->toBe(basename(recordedSink($this->checkout, useRecordedKey: false)->path(), '.json'));

        // The hook beside it, whose own git lookup times out, reads the record rather than refusing
        recordedGit(null);

        expect(recordedHook($this->checkout)[0])->toBe(0);
    } finally {
        $input->close();
        $bridge->wait();
    }

    expect(recordedFiles($this->state))->toBeEmpty();
})->skipOnWindows();

it("adopts a live bridge's record, so a second bridge in the folder shares its sink and is warned", function (): void {
    // Bridge A: git answers, it records its key and holds that sink's seat
    recordedGit($this->checkout.'/.git');
    $first = recordedSink($this->checkout);
    $first->recordKey();

    $firstPath = $first->path();
    expect($first->claimSeat())->toBeTrue();

    // Bridge B, a new process whose git times out: it would key the folder, a different sink
    recordedNewProcess();
    recordedGit(null);
    $second = recordedSink($this->checkout);

    expect($second->recordKey())->toBeNull()
        ->and($second->path())->toBe($first->path())

        // On the shared sink, B's seat claim fails, which is what makes the bridge warn (#320)
        ->and($second->claimSeat())->toBeFalse();

    // The control: with A's seat free, B records its own key instead, and says why
    unset($first);
    recordedNewProcess();
    $third = recordedSink($this->checkout);

    expect($third->recordKey())->toContain('git did not answer in time')
        ->and($third->path())->not->toBe($firstPath);
});

it("keeps the bridge's follower and keep-alive on the record rather than asking git again", function (): void {
    // Git times out for the record, then answers every later call: a reader that asked again
    // would land on another sink, and would show as another git call
    $calls = 0;

    Process::fake(function () use (&$calls): mixed {
        $calls++;

        if ($calls <= PendingEvents::CHECKOUT_ATTEMPTS) {
            $git = new SymfonyProcess(['git']);

            throw new ProcessTimedOutException(new SymfonyTimedOut($git, SymfonyTimedOut::TYPE_GENERAL), new ProcessResult($git));
        }

        return Process::result(output: '/elsewhere/.git'."\n");
    });

    recordedSink($this->checkout)->recordKey();

    // What the bridge's follower (FleetJoin) and keep-alive build, then ask
    recordedSink($this->checkout)->path();
    recordedSink($this->checkout)->turnEndedAt();

    expect($calls)->toBe(PendingEvents::CHECKOUT_ATTEMPTS);
});

it('keeps the real follower and keep-alive of a joined bridge on its record', function (): void {
    // Git times out for the record, then answers: FleetJoin's sink at the join, or the keep-alive's
    // turn mark, asking again would show as a git call beyond the record's
    $calls = 0;

    // Only the sink's lookup is counted: the join also asks git for the repository and work location
    Process::fake(function (PendingProcess $process) use (&$calls): mixed {
        $command = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

        if (! str_contains($command, '--absolute-git-dir')) {
            return Process::result(exitCode: 1);
        }

        $calls++;

        if ($calls <= PendingEvents::CHECKOUT_ATTEMPTS) {
            $git = new SymfonyProcess(['git']);

            throw new ProcessTimedOutException(new SymfonyTimedOut($git, SymfonyTimedOut::TYPE_GENERAL), new ProcessResult($git));
        }

        return Process::result(output: '/elsewhere/.git'."\n");
    });

    $store = new RecordingStore;
    $store->put(RECORDED_SERVICE.'|claude', new Credential('rcouncil_1|RECORDED-INSTALLATION'));

    app()->instance(Credentials::class, new Credentials([$store]));

    Http::fake([
        '*/api/sessions/*' => Http::response('', 204),
        '*/api/sessions' => Http::response(['session_id' => 81, 'token' => 'rcouncil_2|T', 'expires_in' => 3600, 'abilities' => []], 201),
        '*/api/agent/session' => Http::response(['fleet_can_direct' => true], 200),
        '*' => Http::response('', 200),
    ]);

    putenv('ROBOT_COUNCIL_HARNESS=claude');
    putenv('CLAUDE_CONFIG_DIR='.$this->state.'/claude');
    $cwd = getcwd();
    chdir($this->checkout);

    try {
        expect(Artisan::call('mcp', ['--service' => RECORDED_SERVICE, '--auto-join' => true, '--keep-warm' => '30'], new TwoStreamOutput))->toBe(0);
    } finally {
        chdir((string) $cwd);
        putenv('ROBOT_COUNCIL_HARNESS');
        putenv('CLAUDE_CONFIG_DIR');
    }

    // The join happened, so FleetJoin built its sink. Beyond the record's lookups, git was asked once
    // more, by `Checkout::workLocation()` naming the work location at the join -- not for the sink
    expect(Http::recorded(fn (Request $request): bool => str_ends_with($request->url(), '/api/sessions'))->count())->toBe(1)
        ->and($calls)->toBe(PendingEvents::CHECKOUT_ATTEMPTS + 1);
});

it('keeps a running bridge on its own key when another process rewrites the record', function (): void {
    recordedGit($this->checkout.'/.git');
    $bridge = recordedSink($this->checkout);
    $bridge->recordKey();

    $own = $bridge->path();

    // Another bridge, elsewhere, records a different key over this one
    File::put(recordedFiles($this->state)[0], str_repeat('a', 32));

    // This bridge's follower, keep-alive and shutdown clear stay where they were
    expect($bridge->path())->toBe($own)

        // The control: a reader in another process, such as the hook, reads the file as it now is
        ->and(new ReflectionMethod(PendingEvents::class, 'recordedKey')->invoke($bridge))->toBe(str_repeat('a', 32));
});
