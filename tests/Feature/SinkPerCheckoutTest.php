<?php

declare(strict_types=1);

/**
 * One fleet-event sink per checkout, when no project is named (#299).
 *
 * **The defect this pins.** Every session launched from one user-level harness configuration has
 * the same service, harness and project, so the sink was keyed identically for all of a machine's
 * sessions, and the first stop hook to run took every session's events. Measured on one Mac with
 * three bridges sharing a sink.
 *
 * **Real checkouts and a real `pending` process**, because the key is resolved from the working
 * directory, and a hook is a separate process started in the project directory. A test that
 * passed the directory in would not show that the command resolves it.
 *
 * @command  vendor/bin/pest --compact tests/Feature/SinkPerCheckoutTest.php
 */
use App\Support\Checkout;
use App\Support\PendingEvents;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

const SINK_SERVICE = 'https://fleet.example.test';

/**
 * Run git in a directory, failing the test if it fails.
 *
 * @param  list<string>  $arguments
 */
function sinkGit(string $directory, array $arguments): void
{
    $git = new Process(['git', ...$arguments], $directory);
    $git->run();

    if (! $git->isSuccessful()) {
        throw new RuntimeException('git '.implode(' ', $arguments).' failed: '.$git->getErrorOutput());
    }
}

/**
 * A new repository with one commit, so a worktree can be added to it.
 */
function sinkRepository(string $path): string
{
    File::ensureDirectoryExists($path);

    sinkGit($path, ['init', '-q']);
    sinkGit($path, ['-c', 'user.name=probe', '-c', 'user.email=probe@example.test', 'commit', '-q', '--allow-empty', '-m', 'start']);

    return $path;
}

/**
 * One event, told apart by its body.
 *
 * @return array<string, mixed>
 */
function sinkEvent(string $body): array
{
    return ['id' => 1, 'type' => 'directive', 'body' => $body, 'meta' => [], 'created_at' => '2026-09-25T12:00:00+00:00', 'actor' => ['session_id' => 9, 'github_login' => 'otherdev']];
}

/**
 * Run `robot-council pending` the way a stop hook does: its own process, in a directory.
 */
function sinkHook(string $directory, string $state, ?string $projectDirectory = null, string $harness = 'claude'): string
{
    $hook = new Process(
        [PHP_BINARY, base_path('robot-council'), 'pending'],
        $directory,
        [
            'XDG_STATE_HOME' => $state,
            'ROBOT_COUNCIL_SERVICE' => SINK_SERVICE,
            'ROBOT_COUNCIL_HARNESS' => $harness,

            // Unset unless given: this suite may itself run inside a Claude Code session
            'CLAUDE_PROJECT_DIR' => $projectDirectory ?? false,
        ],
    );

    $hook->mustRun();

    return $hook->getOutput();
}

beforeEach(function (): void {
    $this->root = str_replace('\\', '/', sys_get_temp_dir()).'/rc-sink-'.bin2hex(random_bytes(6));
    $this->state = $this->root.'/state';

    putenv('XDG_STATE_HOME='.$this->state);
});

afterEach(function (): void {
    putenv('XDG_STATE_HOME');

    File::deleteDirectory($this->root);
});

it('gives two checkouts two sinks, and each hook reads only its own', function (): void {
    $one = sinkRepository($this->root.'/one');
    $two = sinkRepository($this->root.'/two');

    new PendingEvents(SINK_SERVICE, 'claude', null, $one)->add([sinkEvent('for one')]);
    new PendingEvents(SINK_SERVICE, 'claude', null, $two)->add([sinkEvent('for two')]);

    $readInTwo = sinkHook($two, $this->state);
    $readInOne = sinkHook($one, $this->state);

    expect($readInOne)->toContain('for one')->not->toContain('for two')
        ->and($readInTwo)->toContain('for two')->not->toContain('for one');
});

it('gives a linked worktree a sink apart from its main checkout', function (): void {
    $main = sinkRepository($this->root.'/main');
    sinkGit($main, ['worktree', 'add', '-q', '--detach', $this->root.'/slot']);

    expect(new PendingEvents(SINK_SERVICE, 'claude', null, $main)->path())
        ->not->toBe(new PendingEvents(SINK_SERVICE, 'claude', null, $this->root.'/slot')->path());
});

it('finds the same sink from a subdirectory of the checkout, and no other checkout does', function (): void {
    $repository = sinkRepository($this->root.'/repo');
    $sibling = sinkRepository($this->root.'/sibling');
    File::ensureDirectoryExists($repository.'/src/deep');

    new PendingEvents(SINK_SERVICE, 'claude', null, $repository)->add([sinkEvent('written at the root')]);

    // The sibling first: with one shared sink it would take the event, and the subdirectory would
    // then read nothing
    expect(sinkHook($sibling, $this->state))->toBeEmpty()
        ->and(sinkHook($repository.'/src/deep', $this->state))->toContain('written at the root');
});

it('keys a directory outside any repository by the directory itself', function (): void {
    File::ensureDirectoryExists($this->root.'/plain-a');
    File::ensureDirectoryExists($this->root.'/plain-b');

    new PendingEvents(SINK_SERVICE, 'claude', null, $this->root.'/plain-a')->add([sinkEvent('for plain a')]);

    // The other directory first, for the same reason as the sibling above
    expect(sinkHook($this->root.'/plain-b', $this->state))->toBeEmpty()
        ->and(sinkHook($this->root.'/plain-a', $this->state))->toContain('for plain a');
})->skip(fn (): bool => new Process(['git', 'rev-parse', '--absolute-git-dir'], sys_get_temp_dir())->run() === 0, 'The temporary directory is inside a git repository here.');

it('keeps the key a named project always had, so its sink survives the upgrade', function (): void {
    $one = sinkRepository($this->root.'/one');
    $two = sinkRepository($this->root.'/two');

    // The key before #299, spelled out: the service, the harness and the project, nothing else
    $before = substr(hash('sha256', implode("\0", [SINK_SERVICE, 'claude', 'org/repo'])), 0, 32);

    expect(basename(new PendingEvents(SINK_SERVICE, 'claude', 'org/repo', $one)->path(), '.json'))->toBe($before)
        ->and(new PendingEvents(SINK_SERVICE, 'claude', 'org/repo', $two)->path())
        ->toBe(new PendingEvents(SINK_SERVICE, 'claude', 'org/repo', $one)->path());
});

it('never lands on the sink every session used to share', function (): void {
    $repository = sinkRepository($this->root.'/repo');

    $shared = substr(hash('sha256', implode("\0", [SINK_SERVICE, 'claude', ''])), 0, 32);

    expect(basename(new PendingEvents(SINK_SERVICE, 'claude', null, $repository)->path(), '.json'))->not->toBe($shared);
});

it("keeps the turn-end mark per checkout too, so one session cannot restart another's keep-alive clock", function (): void {
    $one = sinkRepository($this->root.'/one');
    $two = sinkRepository($this->root.'/two');

    new PendingEvents(SINK_SERVICE, 'claude', null, $one)->markTurnEnded();

    expect(new PendingEvents(SINK_SERVICE, 'claude', null, $two)->turnEndedAt())->toBeNull()
        ->and(new PendingEvents(SINK_SERVICE, 'claude', null, $one)->turnEndedAt())->toBeInt();
});

it("resolves the hook's checkout from where Claude Code launched the session, not where the agent moved", function (): void {
    $launched = sinkRepository($this->root.'/launched');
    $moved = sinkRepository($this->root.'/moved');

    new PendingEvents(SINK_SERVICE, 'claude', null, $launched)->add([sinkEvent('for the launched checkout')]);
    new PendingEvents(SINK_SERVICE, 'claude', null, $moved)->add([sinkEvent('for the other checkout')]);

    // The agent ran `cd` into an added directory, so the hook starts there, while
    // `CLAUDE_PROJECT_DIR` still names the directory the session and its bridge were launched in
    $read = sinkHook($moved, $this->state, $launched);

    expect($read)->toContain('for the launched checkout')->not->toContain('for the other checkout');
});

it('asks git again after it could not answer, rather than keeping the fallback', function (): void {
    $later = $this->root.'/later';

    // A directory that does not exist yet: git cannot even start in it, which is the same
    // no-answer a timeout gives, so the key falls back to the directory for this call only
    $whileGitCouldNotAnswer = new PendingEvents(SINK_SERVICE, 'claude', null, $later)->path();

    sinkRepository($later);

    $once = new PendingEvents(SINK_SERVICE, 'claude', null, $later)->path();

    expect($whileGitCouldNotAnswer)->not->toBe($once)
        ->and($once)->toBe(new PendingEvents(SINK_SERVICE, 'claude', null, $later.'/.')->path());
});

it('gives a Cursor bridge and its hook one sink, whatever folders Cursor runs them in', function (): void {
    // #327, as measured on Cursor 3.17.19: the one app-wide bridge runs in the home folder and the
    // stop hook in `~/.cursor`, neither of them the project
    $home = $this->root.'/home';
    $config = $this->root.'/home/.cursor';
    File::ensureDirectoryExists($config);

    new PendingEvents(SINK_SERVICE, 'cursor', null, $home)->add([sinkEvent('for the cursor seat')]);

    expect(sinkHook($config, $this->state, harness: 'cursor'))->toContain('for the cursor seat');
});

it('keys a Cursor sink as it was before #299, so both sides agree with no checkout in it', function (): void {
    $before = substr(hash('sha256', implode("\0", [SINK_SERVICE, 'cursor', ''])), 0, 32);

    expect(basename(new PendingEvents(SINK_SERVICE, 'cursor', null, $this->root)->path(), '.json'))->toBe($before);
});

it('still keeps Claude Code seats in different folders apart', function (): void {
    $one = sinkRepository($this->root.'/one');
    $two = sinkRepository($this->root.'/two');

    expect(new PendingEvents(SINK_SERVICE, 'claude', null, $one)->path())
        ->not->toBe(new PendingEvents(SINK_SERVICE, 'claude', null, $two)->path());
});

it('leaves the Claude Code key exactly as #299 made it: the service, the harness, no project, and the checkout', function (): void {
    $repository = sinkRepository($this->root.'/repo');
    $gitDirectory = Checkout::gitDirectory($repository);

    $expected = substr(hash('sha256', implode("\0", [SINK_SERVICE, 'claude', '', $gitDirectory])), 0, 32);

    expect($gitDirectory)->toBeString()
        ->and(basename(new PendingEvents(SINK_SERVICE, 'claude', null, $repository)->path(), '.json'))->toBe($expected);
});
