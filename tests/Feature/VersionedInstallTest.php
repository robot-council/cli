<?php

declare(strict_types=1);

/**
 * The versioned install: versions side by side, a launcher that starts the newest, and an upgrade
 * that removes nothing a running bridge holds (#280).
 *
 * @command  vendor/bin/pest --compact tests/Feature/VersionedInstallTest.php
 */

use App\Support\Installs;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Process as SymfonyProcess;

require_once dirname(__DIR__, 2).'/launcher/select.php';

beforeEach(function (): void {
    $this->root = str_replace('\\', '/', sys_get_temp_dir()).'/rc-versions-'.bin2hex(random_bytes(6));

    mkdir($this->root.'/versions', 0o777, true);
});

afterEach(function (): void {
    Installs::delete($this->root);
});

/**
 * A version directory that the launcher would run, whose `entry.php` reports what it saw.
 */
function fakeVersion(string $root, string $name, bool $complete = true): string
{
    $directory = $root.'/versions/'.$name;

    mkdir($directory.'/vendor', 0o777, true);

    if ($complete) {
        file_put_contents($directory.'/vendor/autoload.php', '<?php');
        file_put_contents($directory.'/entry.php', <<<'PHP'
            <?php
            // Whether another handle can take the in-use lock exclusively: it cannot, while the
            // launcher holds its shared one.
            $probe = fopen(__DIR__.'/.in-use', 'c');
            $locked = ! flock($probe, LOCK_EX | LOCK_NB);
            echo json_encode(['version' => basename(__DIR__), 'argv' => array_slice($_SERVER['argv'], 1), 'binary' => ARTISAN_BINARY, 'locked' => $locked]);
            PHP);
    }

    return $directory;
}

/**
 * Hold a version's in-use lock, as a running launcher does.
 *
 * @return resource The handle, which the caller unlocks and closes.
 */
function holdInUse(string $directory): mixed
{
    $handle = fopen($directory.'/'.Installs::IN_USE, 'c');

    if ($handle === false) {
        throw new RuntimeException('Could not open the in-use file.');
    }

    flock($handle, LOCK_SH);

    return $handle;
}

/**
 * The version a fake `entry.php` said it was, from what the launcher printed.
 */
function reportedVersion(string $output): ?string
{
    $report = json_decode($output, true);

    return is_array($report) && is_string($report['version'] ?? null) ? $report['version'] : null;
}

/**
 * The launcher, as `upgrade` puts it in `bin/`.
 */
function installLauncherFor(string $root): void
{
    Installs::installLauncher($root, base_path('launcher'));
}

it('chooses the newest complete version, in version order rather than string order', function (): void {
    fakeVersion($this->root, '0.4.9');
    fakeVersion($this->root, '0.4.10');

    // As strings, `0.4.9` sorts after `0.4.10`.
    expect(robot_council_newest($this->root.'/versions'))->toBe('0.4.10');
});

it('ranks a prerelease below the release it precedes', function (): void {
    fakeVersion($this->root, '0.5.0-beta.1');
    fakeVersion($this->root, '0.5.0');

    expect(robot_council_newest($this->root.'/versions'))->toBe('0.5.0');
});

it('never chooses a version being installed, one that is incomplete, or a directory that is not a version', function (): void {
    fakeVersion($this->root, '0.4.9');
    fakeVersion($this->root, '0.4.11', complete: false);
    fakeVersion($this->root, '.installing-0.4.12-abcd');
    fakeVersion($this->root, 'latest');

    // A copy somebody made, which would rank above everything if its name were read as a version.
    fakeVersion($this->root, '9.9.9.bak');

    expect(robot_council_newest($this->root.'/versions'))->toBe('0.4.9');
});

it('chooses nothing when nothing is installed', function (): void {
    expect(robot_council_newest($this->root.'/versions'))->toBeNull()
        ->and(robot_council_newest($this->root.'/absent'))->toBeNull();
});

it('runs the newest version in process, with the arguments and the name', function (): void {
    fakeVersion($this->root, '0.4.9');
    fakeVersion($this->root, '0.4.10');
    installLauncherFor($this->root);

    $process = new SymfonyProcess([PHP_BINARY, $this->root.'/bin/robot-council.php', 'pending', '--peek']);
    $process->run();

    expect($process->getExitCode())->toBe(0)
        ->and(json_decode($process->getOutput(), true))->toBe([
            'version' => '0.4.10',
            'argv' => ['pending', '--peek'],
            'binary' => 'robot-council',

            // The fake entry takes no lock of its own; the real `entry.php` does, tested below.
            'locked' => false,
        ]);
});

it('runs through the POSIX shim, including through a symlink to it', function (): void {
    fakeVersion($this->root, '0.4.10');
    installLauncherFor($this->root);
    mkdir($this->root.'/elsewhere');
    symlink($this->root.'/bin/robot-council', $this->root.'/elsewhere/robot-council');

    $process = new SymfonyProcess(['sh', $this->root.'/elsewhere/robot-council', 'about']);
    $process->run();

    expect($process->getExitCode())->toBe(0)
        ->and(reportedVersion($process->getOutput()))->toBe('0.4.10');
})->skipOnWindows();

it('runs through the Windows shim', function (): void {
    fakeVersion($this->root, '0.4.10');
    installLauncherFor($this->root);

    $process = new SymfonyProcess(['cmd', '/c', str_replace('/', '\\', $this->root.'/bin/robot-council.cmd'), 'about']);
    $process->run();

    expect($process->getExitCode())->toBe(0)
        ->and(reportedVersion($process->getOutput()))->toBe('0.4.10');
})->skip(PHP_OS_FAMILY !== 'Windows', 'The .cmd shim runs only under cmd.exe.');

it('says so and fails when no version is installed', function (): void {
    installLauncherFor($this->root);

    $process = new SymfonyProcess([PHP_BINARY, $this->root.'/bin/robot-council.php', 'about']);
    $process->run();

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput())->toBeEmpty()
        ->and($process->getErrorOutput())->toContain('no version is installed under');
});

it('tells a version in use from one nothing holds', function (): void {
    $held = fakeVersion($this->root, '0.4.9');
    $idle = fakeVersion($this->root, '0.4.10');

    $handle = holdInUse($held);

    expect(Installs::inUse($held))->toBeTrue()
        ->and(Installs::inUse($idle))->toBeFalse();

    flock($handle, LOCK_UN);
    fclose($handle);

    expect(Installs::inUse($held))->toBeFalse();
});

it('removes old versions nothing holds, and keeps the newest, the running one, and any in use', function (): void {
    fakeVersion($this->root, '0.4.7');
    $held = fakeVersion($this->root, '0.4.8');
    $running = fakeVersion($this->root, '0.4.9');
    fakeVersion($this->root, '0.4.10');

    $handle = holdInUse($held);

    $cleared = Installs::removeUnused($this->root, $running);

    flock($handle, LOCK_UN);
    fclose($handle);

    expect($cleared)->toBe(['removed' => ['0.4.7'], 'kept' => ['0.4.8', '0.4.9'], 'failed' => []])
        ->and(Installs::versions($this->root))->toBe(['0.4.8', '0.4.9', '0.4.10']);
});

it('puts the launcher in place, and leaves a file that already matches untouched', function (): void {
    expect(Installs::installLauncher($this->root, base_path('launcher'))['written'])->toBe(Installs::LAUNCHER)
        ->and(Installs::installLauncher($this->root, base_path('launcher')))->toBe(['written' => [], 'left' => []]);
});

it('brings a launcher file that differs up to date, by a rename over it', function (): void {
    installLauncherFor($this->root);
    file_put_contents($this->root.'/bin/robot-council.php', '<?php // an older launcher');

    expect(Installs::installLauncher($this->root, base_path('launcher')))->toBe(['written' => ['robot-council.php'], 'left' => []])
        ->and(file_get_contents($this->root.'/bin/robot-council.php'))->toBe(file_get_contents(base_path('launcher/robot-council.php')))
        ->and(glob($this->root.'/bin/*.new'))->toBe([]);
});

it('knows a versioned install and a single-directory one by their layout', function (): void {
    $version = fakeVersion($this->root, '0.4.10');
    installLauncherFor($this->root);

    expect(Installs::rootOf($version))->toBe($this->root)
        ->and(Installs::rootOf('/home/me/.local/robot-council/vendor/robot-council/cli'))->toBeNull()
        ->and(Installs::projectOf('/home/me/.local/robot-council/vendor/robot-council/cli'))->toBe('/home/me/.local/robot-council')
        ->and(Installs::projectOf($version))->toBeNull();
});

/**
 * Fake Composer: `create-project` writes a version into the staging directory it was given.
 *
 * @param  bool  $complete  Whether the version it writes carries the launcher.
 * @param  int  $exit  What Composer exits with.
 */
function fakeComposer(bool $complete = true, int $exit = 0): void
{
    Process::fake(function (PendingProcess $process) use ($complete, $exit) {
        $command = (array) $process->command;
        $staging = $command[3] ?? null;

        // A failed install can leave a partial directory behind, which must not outlive it.
        if ($exit !== 0 && is_string($staging)) {
            mkdir($staging.'/vendor', 0o777, true);
        }

        if ($exit === 0 && is_string($staging)) {
            mkdir($staging.'/vendor', 0o777, true);
            file_put_contents($staging.'/vendor/autoload.php', '<?php');

            if ($complete) {
                file_put_contents($staging.'/entry.php', '<?php');
                mkdir($staging.'/launcher');

                foreach (Installs::LAUNCHER as $file) {
                    copy(base_path('launcher/'.$file), $staging.'/launcher/'.$file);
                }
            }
        }

        return Process::result(errorOutput: $exit === 0 ? '' : 'Could not find package', exitCode: $exit);
    });
}

it('installs the newest stable release beside the others, puts the launcher in place, and clears what nothing runs', function (): void {
    Http::fake(['repo.packagist.org/*' => Http::response(['packages' => ['robot-council/cli' => [
        ['version' => 'v0.5.0-beta.1'], ['version' => 'v0.4.17'], ['version' => 'v0.4.16'],
    ]]], 200)]);
    fakeComposer();
    fakeVersion($this->root, '0.4.15');

    $exit = Artisan::call('upgrade', ['--root' => $this->root]);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and(Installs::versions($this->root))->toBe(['0.4.17'])
        ->and(robot_council_newest($this->root.'/versions'))->toBe('0.4.17')
        ->and($this->root.'/bin/robot-council.php')->toBeFile()
        ->and($output)->toContain('Installed 0.4.17')
        ->toContain('Removed 0.4.15');

    Process::assertRan(fn (PendingProcess $process): bool => ((array) $process->command)[1] === 'create-project'
        && ((array) $process->command)[4] === '0.4.17'
        && in_array('--no-dev', (array) $process->command, true));

    // Nothing half-installed is left behind.
    expect(glob($this->root.'/versions/.installing-*'))->toBe([]);
});

it('installs a named version, and does nothing but tidy up when it is already installed', function (): void {
    fakeComposer();
    fakeVersion($this->root, '0.4.17');

    expect(Artisan::call('upgrade', ['version' => 'v0.4.17', '--root' => $this->root]))->toBe(0)
        ->and(Artisan::output())->toContain('already installed');

    Process::assertNothingRan();
});

it('leaves nothing behind when Composer fails, and says why', function (): void {
    fakeComposer(exit: 1);

    expect(Artisan::call('upgrade', ['version' => '0.4.17', '--root' => $this->root]))->toBe(1)
        ->and(Artisan::output())->toContain('Could not find package')
        ->and(Installs::versions($this->root))->toBeEmpty()
        ->and(glob($this->root.'/versions/.installing-*'))->toBe([]);
});

it('refuses a release from before the launcher existed, which the launcher would never run', function (): void {
    fakeComposer(complete: false);

    expect(Artisan::call('upgrade', ['version' => '0.4.12', '--root' => $this->root]))->toBe(1)
        ->and(Artisan::output())->toContain('predates the versioned install')
        ->and(Installs::versions($this->root))->toBeEmpty();
});

it('refuses a version that is not one', function (): void {
    fakeComposer();

    expect(Artisan::call('upgrade', ['version' => 'latest; rm -rf /', '--root' => $this->root]))->toBe(1);

    Process::assertNothingRan();
});

it('never counts a directory the launcher would skip as the newest, so it cannot remove every version that runs', function (): void {
    // The review's reproduction: an empty `0.7.0/` above two complete versions.
    fakeVersion($this->root, '0.5.0');
    fakeVersion($this->root, '0.6.0');
    fakeVersion($this->root, '0.7.0', complete: false);

    Installs::removeUnused($this->root);

    expect(Installs::versions($this->root))->toBe(['0.6.0'])
        ->and(robot_council_chosen($this->root))->toBe('0.6.0')
        ->and($this->root.'/versions/0.7.0')->toBeDirectory();
});

it('runs a pinned version rather than the newest, and keeps it through a cleanup', function (): void {
    fakeVersion($this->root, '0.5.5');
    fakeVersion($this->root, '0.6.0');
    file_put_contents($this->root.'/pinned', "0.5.5\n");

    Installs::removeUnused($this->root);

    expect(robot_council_chosen($this->root))->toBe('0.5.5')
        ->and(Installs::versions($this->root))->toBe(['0.5.5', '0.6.0']);
});

it('ignores a pin naming a version that is not installed', function (): void {
    fakeVersion($this->root, '0.6.0');
    file_put_contents($this->root.'/pinned', "0.5.5\n");

    expect(robot_council_chosen($this->root))->toBe('0.6.0');
});

it('rolls back to a named older version, and returns to the newest without a name', function (): void {
    Http::fake(['repo.packagist.org/*' => Http::response(['packages' => ['robot-council/cli' => [['version' => 'v0.6.0']]]], 200)]);
    fakeComposer();
    fakeVersion($this->root, '0.6.0');

    // The review's reproduction: this used to install 0.5.5 and then remove it as unused.
    expect(Artisan::call('upgrade', ['version' => '0.5.5', '--root' => $this->root]))->toBe(0)
        ->and(Artisan::output())->toContain('Pinned 0.5.5')
        ->and(robot_council_chosen($this->root))->toBe('0.5.5')
        ->and(Installs::versions($this->root))->toBe(['0.5.5', '0.6.0']);

    expect(Artisan::call('upgrade', ['--root' => $this->root]))->toBe(0)
        ->and(Artisan::output())->toContain('Removed the pin')
        ->and(robot_council_chosen($this->root))->toBe('0.6.0')
        ->and(Installs::versions($this->root))->toBe(['0.6.0']);
});

it('removes a staging directory an interrupted upgrade left, and leaves one that may still be in use', function (): void {
    fakeVersion($this->root, '0.6.0');
    mkdir($this->root.'/versions/.installing-0.6.1-old');
    mkdir($this->root.'/versions/.installing-0.6.2-new');
    touch($this->root.'/versions/.installing-0.6.1-old', time() - 3600);

    Installs::removeUnused($this->root);

    expect($this->root.'/versions/.installing-0.6.1-old')->not->toBeDirectory()
        ->and($this->root.'/versions/.installing-0.6.2-new')->toBeDirectory();
});

it('marks a version in use from entry.php itself, so a version started without the launcher is marked too', function (): void {
    // The real entry.php, with an autoloader that reports whether the lock is held and stops.
    $directory = $this->root.'/versions/0.6.0';
    mkdir($directory.'/vendor', 0o777, true);
    copy(base_path('entry.php'), $directory.'/entry.php');
    file_put_contents($directory.'/vendor/autoload.php', <<<'PHP'
        <?php
        $probe = fopen(dirname(__DIR__).'/.in-use', 'c');
        echo flock($probe, LOCK_EX | LOCK_NB) ? 'unlocked' : 'locked';
        exit(0);
        PHP);

    $process = new SymfonyProcess([PHP_BINARY, $directory.'/entry.php', 'mcp']);
    $process->run();

    expect($process->getOutput())->toBe('locked')
        ->and($process->getErrorOutput())->toBeEmpty();
});

it("passes the version's exit code back through the POSIX shim", function (): void {
    $directory = fakeVersion($this->root, '0.6.0');
    file_put_contents($directory.'/entry.php', '<?php exit(3);');
    installLauncherFor($this->root);

    $process = new SymfonyProcess(['sh', $this->root.'/bin/robot-council']);
    $process->run();

    expect($process->getExitCode())->toBe(3);
})->skipOnWindows();

it("passes the version's exit code back through the Windows shim", function (): void {
    $directory = fakeVersion($this->root, '0.6.0');
    file_put_contents($directory.'/entry.php', '<?php exit(3);');
    installLauncherFor($this->root);

    $process = new SymfonyProcess(['cmd', '/c', str_replace('/', '\\', $this->root.'/bin/robot-council.cmd')]);
    $process->run();

    expect($process->getExitCode())->toBe(3);
})->skip(PHP_OS_FAMILY !== 'Windows', 'The .cmd shim runs only under cmd.exe.');

it('reports a version it could not fully remove as failed, not removed', function (): void {
    fakeVersion($this->root, '0.5.0');
    fakeVersion($this->root, '0.6.0');

    // A directory whose entries cannot be removed, as a file something holds cannot be on Windows.
    mkdir($this->root.'/versions/0.5.0/stuck');
    touch($this->root.'/versions/0.5.0/stuck/held');
    chmod($this->root.'/versions/0.5.0/stuck', 0o555);

    $cleared = Installs::removeUnused($this->root);

    chmod($this->root.'/versions/0.5.0/stuck', 0o755);

    expect($cleared['failed'])->toBe(['0.5.0'])
        ->and($cleared['removed'])->toBeEmpty();
})->skipOnWindows();

it('reads the installed versions in a process the launcher started, where select.php is already loaded from bin', function (): void {
    // #280, measured on the released v0.4.18: the launcher loads `bin/select.php`, and `upgrade`
    // then loaded the version's own `launcher/select.php` -- a different path -- and died with
    // "Cannot redeclare function robot_council_complete()".
    fakeVersion($this->root, '0.6.0');
    installLauncherFor($this->root);

    $code = sprintf(
        'require %s; require %s; echo implode(",", '.Installs::class.'::versions(%s));',
        var_export($this->root.'/bin/select.php', true),
        var_export(base_path('vendor/autoload.php'), true),
        var_export($this->root, true),
    );

    $process = new SymfonyProcess([PHP_BINARY, '-r', $code]);
    $process->run();

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toBe('0.6.0');
});
