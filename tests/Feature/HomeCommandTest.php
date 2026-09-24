<?php

declare(strict_types=1);

/**
 * What `robot-council` does when no command, or no real command, is named.
 *
 * **Run as a process, not through `Artisan::call()`**, because the defect lives in the kernel's
 * `handle()`: Laravel Zero's `ensureDefaultCommand()` is what turns an unknown first argument into
 * an argument of the default command, and `Artisan::call()` never reaches it (#248).
 *
 * @command  vendor/bin/pest --compact tests/Feature/HomeCommandTest.php
 */

use Symfony\Component\Process\Process;

/**
 * Run the command line with these arguments.
 *
 * @param  list<string>  $arguments  What follows `robot-council`.
 * @param  array<string, string>  $environment  Variables to set, on top of this process's own.
 */
function runRobotCouncil(array $arguments, array $environment = []): Process
{
    $process = new Process([PHP_BINARY, base_path('robot-council'), ...$arguments], base_path(), $environment === [] ? null : $environment);

    $process->run();

    return $process;
}

it('refuses a command that does not exist, naming it on stderr and nothing on stdout', function (): void {
    $process = runRobotCouncil(['nosuchcommand']);

    // Before #248 this printed the summary and exited 0, which a stop hook would have handed to an
    // agent as though it were fleet events.
    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput())->toBeEmpty()
        ->and($process->getErrorOutput())->toContain('Command "nosuchcommand" is not defined.');
});

it('suggests the command a near miss was probably meant to be', function (): void {
    $process = runRobotCouncil(['pendng']);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('pending');
});

it('names the unknown command even when options follow it, as a stop hook would pass them', function (): void {
    // The hook's own shape with the command misspelled. Symfony would otherwise refuse
    // `--project` on stdout and never mention the command that was actually wrong.
    $process = runRobotCouncil(['pendng', '--project=org/repo']);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput())->toBeEmpty()
        ->and($process->getErrorOutput())->toContain('Command "pendng" is not defined.');
});

it('still runs `pending` with the arguments the stop hook passes, printing nothing when nothing waits', function (): void {
    $state = sys_get_temp_dir().'/rc-home-'.bin2hex(random_bytes(6));

    try {
        $process = runRobotCouncil(['pending', '--project=org/repo'], [
            'XDG_STATE_HOME' => $state,
            'ROBOT_COUNCIL_SERVICE' => 'https://fleet.example.test',
        ]);

        expect($process->getExitCode())->toBe(0)
            ->and($process->getOutput())->toBeEmpty();
    } finally {
        @rmdir($state.'/robot-council/pending');
        @rmdir($state.'/robot-council');
        @rmdir($state);
    }
});

it('refuses a namespaced name that does not exist', function (): void {
    expect(runRobotCouncil(['nosuch:thing'])->getExitCode())->toBe(1);
});

it('still prints the summary and succeeds when no command is named', function (): void {
    $process = runRobotCouncil([]);

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('pending')
        ->toContain('enroll')

        // It is `list` that prints this, and `list` stays out of the list it prints.
        ->not->toContain('home');
});

it('answers a bare `--help` with the help for `list`, the command it runs', function (): void {
    // Symfony answers `--help` with no command using the default command's help, so a default
    // under any other name would describe a command nobody can see.
    $process = runRobotCouncil(['--help']);

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('list [options] [--] [<namespace>]')
        ->not->toContain('home');
});

it('keeps `list --raw`, which the production-install check reads', function (): void {
    $process = runRobotCouncil(['list', '--raw']);

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toStartWith('about ');
});

it('still runs a real command by its name', function (): void {
    $process = runRobotCouncil(['about']);

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('robot-council');
});
