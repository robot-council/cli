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
 */
function runRobotCouncil(array $arguments): Process
{
    $process = new Process([PHP_BINARY, base_path('robot-council'), ...$arguments], base_path());

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

it('refuses a namespaced name that does not exist', function (): void {
    expect(runRobotCouncil(['nosuch:thing'])->getExitCode())->toBe(1);
});

it('still prints the summary and succeeds when no command is named', function (): void {
    $process = runRobotCouncil([]);

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('pending')
        ->toContain('enroll')

        // The default command itself stays out of the list it prints.
        ->not->toContain('home ');
});

it('still runs a real command by its name', function (): void {
    $process = runRobotCouncil(['about']);

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('robot-council');
});
