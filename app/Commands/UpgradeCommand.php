<?php

declare(strict_types=1);

namespace App\Commands;

use App\Support\Installs;
use App\Support\Version;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;
use Throwable;

/**
 * Install a version beside the others, so upgrading needs nobody at the machine (#280).
 *
 * **Nothing a running bridge holds is touched.** The new version goes into a directory of its own
 * under `versions/`, the launcher on `PATH` starts the newest one for each new process, and a
 * version still running is removed only by a later run, once nothing holds it. That is what makes
 * the upgrade safe on Windows with every session's bridge running, where upgrading a single
 * directory in place failed partway and left the install broken.
 *
 * **From a single-directory install, it sets up the versioned layout beside it**, in the same
 * Composer project, and touches none of the old files. Pointing `PATH` at the launcher is then a
 * one-time step, which the command says.
 */
#[Description('Install a version of this command line beside the ones already installed')]
#[Signature('upgrade
    {version? : The version to install, such as 0.4.17; the newest release when omitted}
    {--root= : The versioned install to upgrade, when this command is not running from one}')]
final class UpgradeCommand extends Command
{
    /**
     * Where Packagist lists this package's versions.
     */
    public const string PACKAGIST = 'https://repo.packagist.org/p2/robot-council/cli.json';

    /**
     * How long installing one version may take, in seconds.
     */
    public const int INSTALL_SECONDS = 600;

    /**
     * Install the version, put the launcher in place when it is missing, and clear what nothing uses.
     *
     * @param  Factory  $http  The HTTP client, for Packagist.
     * @return int The exit code.
     */
    public function handle(Factory $http): int
    {
        $root = $this->root();

        if ($root === null) {
            $this->components->error('This is not an install `upgrade` can manage. Pass --root with the directory to install versions under.');

            return self::FAILURE;
        }

        $version = $this->version($http);

        if ($version === null) {
            return self::FAILURE;
        }

        $target = $root.'/versions/'.$version;

        if (is_file($target.'/entry.php')) {
            $this->components->info(sprintf('%s is already installed at %s.', $version, $target));
        } elseif (! $this->install($root, $version)) {
            return self::FAILURE;
        }

        $written = Installs::installLauncher($root, $target.'/launcher');

        if ($written !== []) {
            $this->components->info(sprintf('Put the launcher in %s/bin.', $root));
        }

        $running = Installs::rootOf(base_path()) === $root ? base_path() : null;
        $cleared = Installs::removeUnused($root, $running);

        foreach ($cleared['removed'] as $removed) {
            $this->line(sprintf('Removed %s, which nothing was running.', $removed));
        }

        foreach ($cleared['kept'] as $kept) {
            $this->line(sprintf('Kept %s, which is still running; a later upgrade removes it.', $kept));
        }

        $this->pathAdvice($root);

        return self::SUCCESS;
    }

    /**
     * The versioned install to manage: named, this one, or one beside a single-directory install.
     */
    private function root(): ?string
    {
        $named = $this->option('root');

        if (\is_string($named) && $named !== '') {
            return rtrim(str_replace('\\', '/', $named), '/');
        }

        return Installs::rootOf(base_path()) ?? Installs::projectOf(base_path());
    }

    /**
     * The version to install, as named or as Packagist's newest release.
     *
     * @param  Factory  $http  The HTTP client.
     */
    private function version(Factory $http): ?string
    {
        $named = $this->argument('version');

        if (\is_string($named) && $named !== '') {
            $version = ltrim($named, 'v');

            if (preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/D', $version) !== 1) {
                $this->components->error(sprintf('"%s" is not a version, such as 0.4.17.', $named));

                return null;
            }

            return $version;
        }

        try {
            $listed = $http->acceptJson()->timeout(30)->get(self::PACKAGIST)->json('packages.'.Version::PACKAGE);
        } catch (Throwable) {
            $listed = null;
        }

        $newest = null;

        foreach (\is_array($listed) ? $listed : [] as $release) {
            $candidate = \is_array($release) && \is_string($release['version'] ?? null) ? ltrim($release['version'], 'v') : null;

            // Stable releases only: a suffixed tag is one a resolver skips by default, and so does this.
            if ($candidate !== null && preg_match('/^\d+\.\d+\.\d+$/D', $candidate) === 1
                && ($newest === null || version_compare($candidate, $newest, '>'))) {
                $newest = $candidate;
            }
        }

        if ($newest === null) {
            $this->components->error('Could not read the newest release from Packagist. Name the version to install.');
        }

        return $newest;
    }

    /**
     * Install one version into a directory of its own, whole or not at all.
     *
     * **Into a dot-prefixed directory, renamed into place when complete**, so the launcher -- which
     * skips dot-prefixed names -- never starts a version that is half installed.
     *
     * @param  string  $root  The versioned install's root.
     * @param  string  $version  The version to install.
     */
    private function install(string $root, string $version): bool
    {
        if (! is_dir($root.'/versions')) {
            mkdir($root.'/versions', 0o755, true);
        }

        $staging = sprintf('%s/versions/.installing-%s-%s', $root, $version, bin2hex(random_bytes(4)));

        $this->line(sprintf('Installing %s…', $version));

        $result = Process::timeout(self::INSTALL_SECONDS)->run([
            $this->composer(), 'create-project', Version::PACKAGE, $staging, $version,
            '--no-dev', '--no-interaction', '--no-progress', '--prefer-dist',
        ]);

        if (! $result->successful()) {
            Installs::delete($staging);
            $this->components->error(sprintf('Composer could not install %s: %s', $version, trim($result->errorOutput()) ?: 'exit '.$result->exitCode()));

            return false;
        }

        // A release from before the launcher existed has no `entry.php`, and the launcher would
        // never choose it; installing it here would look like success and change nothing.
        if (! is_file($staging.'/entry.php') || ! is_dir($staging.'/launcher')) {
            Installs::delete($staging);
            $this->components->error(sprintf('%s predates the versioned install, which needs a release carrying #280.', $version));

            return false;
        }

        if (! @rename($staging, $root.'/versions/'.$version)) {
            Installs::delete($staging);
            $this->components->error(sprintf('Could not move %s into place.', $version));

            return false;
        }

        $this->components->info(sprintf('Installed %s at %s/versions/%s.', $version, $root, $version));

        return true;
    }

    /**
     * The Composer to run: `COMPOSER_BINARY` when set, the one on `PATH` otherwise.
     */
    private function composer(): string
    {
        $named = getenv('COMPOSER_BINARY');

        return \is_string($named) && $named !== '' ? $named : 'composer';
    }

    /**
     * Say how to put the launcher on `PATH`, when this process was not started through it.
     *
     * @param  string  $root  The versioned install's root.
     */
    private function pathAdvice(string $root): void
    {
        if (Installs::rootOf(base_path()) === $root) {
            return;
        }

        $this->newLine();
        $this->line(sprintf('Once, put %s/bin on your PATH in place of the old install, so new sessions start the newest version.', $root));
    }
}
