<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * The external commands `new` drives, in one place so a test can fake them.
 *
 * Every one of these is somebody else's program -- Composer's, or the application's own artisan --
 * and each can fail for reasons this command cannot anticipate. So the contract here is narrow: run
 * it, and throw with whatever the program said when it fails. Scaffolding half an application and
 * reporting success is the outcome worth ruling out.
 *
 * **The timeout is generous rather than absent.** `composer create-project` on a cold cache pulls
 * the better part of a Laravel install, and a default 60 seconds fails a perfectly healthy run on a
 * slow connection. No timeout at all would instead hang forever on a network that stopped
 * answering, which is worse, because nothing would say why.
 */
final class Scaffold
{
    /**
     * How long any one external command may run.
     */
    public const int TIMEOUT_SECONDS = 900;

    /**
     * Where `robot-council/core` is fetched from, since it is not on Packagist.
     */
    public const string CORE_REPOSITORY = 'https://github.com/robot-council/core.git';

    /**
     * The constraint the host application pins the package at.
     */
    public const string CORE_CONSTRAINT = 'robot-council/core:dev-main';

    /**
     * Create a Laravel application in a directory beneath the working directory.
     *
     * @param  string  $directory  The directory to create.
     * @param  string  $in  The directory to create it beneath.
     *
     * @throws RuntimeException When Composer failed.
     */
    public function createProject(string $directory, string $in): void
    {
        $this->run(['composer', 'create-project', 'laravel/laravel', $directory, '--no-interaction'], $in);
    }

    /**
     * Point Composer at this package's repository and require it.
     *
     * Two commands rather than one, and the order matters: requiring a package Composer has no
     * repository for fails with "could not be found in any version", which reads as the package not
     * existing rather than as nowhere having been told where to look.
     *
     * **`--with-all-dependencies` is not optional here, and no faked test can show it.** A fresh
     * `laravel/laravel` locks Guzzle 8, while `robot-council/core` requires `laravel/socialite`,
     * which requires `league/oauth1-client ^1.11`, which allows only Guzzle 6 or 7. A bare
     * `composer require` is a partial update and refuses to move a package the lock already fixed,
     * so it fails with a resolution error naming Guzzle rather than anything about this package.
     * Measured on 2026-09-18 by scaffolding for real: every unit test passed and the command could
     * not install the package it exists to install.
     *
     * @param  string  $application  The application's directory.
     *
     * @throws RuntimeException When Composer failed.
     */
    public function requireCore(string $application): void
    {
        $this->run(['composer', 'config', 'repositories.robot-council', 'vcs', self::CORE_REPOSITORY], $application);
        $this->run(['composer', 'require', self::CORE_CONSTRAINT, '--with-all-dependencies', '--no-interaction'], $application);
    }

    /**
     * Run the package's installer and the migrations it writes.
     *
     * @param  string  $application  The application's directory.
     *
     * @throws RuntimeException When either step failed.
     */
    public function install(string $application): void
    {
        $this->run(['php', 'artisan', 'robot-council:install', '--no-interaction'], $application);
        $this->run(['php', 'artisan', 'migrate', '--force', '--no-interaction'], $application);
    }

    /**
     * Run one command, and throw with what it said if it failed.
     *
     * @param  list<string>  $command  The command and its arguments.
     * @param  string  $directory  Where to run it.
     *
     * @throws RuntimeException When the command exited non-zero.
     */
    private function run(array $command, string $directory): void
    {
        $result = Process::path($directory)
            ->timeout(self::TIMEOUT_SECONDS)
            ->run($command);

        if ($result->failed()) {
            // The program's own stderr, falling back to stdout: Composer reports a resolution
            // failure on stdout, so reporting only stderr would throw with nothing in it
            $said = trim($result->errorOutput()) !== '' ? trim($result->errorOutput()) : trim($result->output());

            throw new RuntimeException(sprintf(
                '`%s` failed. %s',
                implode(' ', $command),
                $said === '' ? 'It said nothing about why.' : $said
            ));
        }
    }
}
