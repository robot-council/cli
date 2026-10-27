<?php

declare(strict_types=1);

/**
 * The bridge and the stop hook reach one sink on Windows, though they are started by different
 * shells (#268).
 *
 * **The defect this pins.** Windows does not set `HOME`. A bridge started from PowerShell had no
 * `HOME` and wrote its sink under `%TEMP%`, while the stop hook, run under Git Bash where `HOME` is
 * an MSYS path, read the user folder. Nothing was delivered. `SinkDirectoryTest` pins the
 * resolution order inside one process; this runs the second process the way Claude Code starts it,
 * so the pair is checked rather than each half.
 *
 * **Windows only, because the shell difference is the point.** Elsewhere the bridge and the hook
 * both see `HOME`, and there is no second shell to disagree with.
 *
 * @command  vendor/bin/pest --compact tests/Feature/SinkAcrossShellsTest.php
 */
use App\Support\PendingEvents;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

const ACROSS_SHELLS_SERVICE = 'https://fleet.example.test';

/**
 * Whether this machine can run the hook as Claude Code does on Windows: under Git Bash, with
 * `cygpath` to turn a Windows folder into the MSYS form `HOME` takes there.
 */
function gitBashWithCygpath(): bool
{
    if (PHP_OS_FAMILY !== 'Windows') {
        return false;
    }

    return array_any(['C:\Program Files\Git\bin\bash.exe', 'C:\Program Files (x86)\Git\bin\bash.exe'], fn (string $candidate): bool => is_executable($candidate));
}

/**
 * The Git Bash that runs the hook, as the documented hook command names it.
 */
function gitBashPath(): string
{
    foreach (['C:\Program Files\Git\bin\bash.exe', 'C:\Program Files (x86)\Git\bin\bash.exe'] as $candidate) {
        if (is_executable($candidate)) {
            return $candidate;
        }
    }

    throw new RuntimeException('No Git Bash on this machine.');
}

/**
 * Run a shell command under Git Bash with the variables a hook gets from Claude Code, and `HOME`
 * set the way Git Bash sets it: to an MSYS path.
 *
 * @param  string  $profile  The user folder, as Windows writes it.
 * @param  string  $command  The shell to run.
 */
function underGitBash(string $profile, string $command): Process
{
    $process = new Process(
        [gitBashPath(), '-c', 'export HOME="$(cygpath -u "$RC_PROFILE")"; '.$command],
        getcwd() ?: null,
        [
            'RC_PROFILE' => $profile,
            'USERPROFILE' => $profile,
            'RC_PHP' => str_replace('\\', '/', PHP_BINARY),
            'RC_BIN' => str_replace('\\', '/', base_path('robot-council')),
            'ROBOT_COUNCIL_SERVICE' => ACROSS_SHELLS_SERVICE,
            'ROBOT_COUNCIL_HARNESS' => 'claude',
            'CLAUDE_PROJECT_DIR' => getcwd() ?: false,
            'XDG_STATE_HOME' => false,
        ],
        timeout: 60,
    );

    $process->run();

    return $process;
}

it('shows the hook a HOME in MSYS form, so the cases below exercise the difference', function (): void {
    $profile = sys_get_temp_dir();

    $process = underGitBash($profile, 'printf "%s" "$HOME"');

    // The control: `HOME` here begins with a slash, as it does under Claude Code's hook, and not
    // with a drive letter, which is what the bridge's `USERPROFILE` looks like.
    expect($process->getOutput())->toStartWith('/')
        ->and($profile)->toMatch('/^[A-Za-z]:/');
})->skip(fn (): bool => ! gitBashWithCygpath(), 'Needs Git Bash with cygpath on Windows.');

it('delivers to the hook what a bridge with no HOME wrote', function (): void {
    $profile = rtrim(sys_get_temp_dir(), '\\/').'\\rc-across-'.bin2hex(random_bytes(6));

    File::ensureDirectoryExists($profile);

    $saved = [];

    foreach (['XDG_STATE_HOME', 'HOME', 'USERPROFILE'] as $variable) {
        $saved[$variable] = getenv($variable);
    }

    try {
        // The bridge as PowerShell starts it: no `HOME`, no `XDG_STATE_HOME`, a `USERPROFILE`.
        putenv('XDG_STATE_HOME');
        putenv('HOME');
        putenv('USERPROFILE='.$profile);

        new PendingEvents(ACROSS_SHELLS_SERVICE, 'claude')->add([
            ['id' => 1, 'type' => 'directive', 'body' => 'across-shells-probe', 'meta' => [], 'created_at' => '2026-10-27T12:00:00+00:00', 'actor' => ['session_id' => 9, 'github_login' => 'otherdev']],
        ]);
    } finally {
        foreach ($saved as $variable => $value) {
            putenv($value === false ? $variable : $variable.'='.$value);
        }
    }

    try {
        $hook = underGitBash($profile, '"$RC_PHP" "$RC_BIN" pending');
    } finally {
        File::deleteDirectory($profile);
    }

    expect($hook->getExitCode())->toBe(0)
        ->and($hook->getOutput())->toContain('across-shells-probe');
})->skip(fn (): bool => ! gitBashWithCygpath(), 'Needs Git Bash with cygpath on Windows.');
