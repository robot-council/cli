<?php

declare(strict_types=1);

namespace App\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The versioned install: each version in its own directory, behind a launcher (#280).
 *
 * ```
 * <root>/bin/robot-council        the launcher's POSIX shim, on PATH
 * <root>/bin/robot-council.cmd    its Windows shim
 * <root>/bin/robot-council.php    the launcher
 * <root>/versions/0.4.17/         one installed version, a complete project
 * ```
 *
 * **Why side by side.** Windows refuses to delete a file a running process holds open, and every
 * running bridge holds its install's files open, so an upgrade in place failed partway on a
 * machine with bridges running and left the install broken. Installing each version beside the
 * others removes nothing a running bridge holds, so an upgrade needs nobody at the machine.
 */
final class Installs
{
    /**
     * The file a running version holds a shared lock on, so a cleanup can tell it is in use.
     */
    public const string IN_USE = '.in-use';

    /**
     * The launcher's files, as the package ships them under `launcher/` and as `bin/` holds them.
     *
     * @var list<string>
     */
    public const array LAUNCHER = ['robot-council', 'robot-council.cmd', 'robot-council.php', 'select.php'];

    /**
     * The root of the versioned install this code runs from, or null when it is not one.
     *
     * @param  string  $basePath  This version's own directory.
     */
    public static function rootOf(string $basePath): ?string
    {
        $parent = \dirname(self::normalize($basePath));

        return basename($parent) === 'versions' && is_dir(\dirname($parent).'/bin') ? \dirname($parent) : null;
    }

    /**
     * A path with forward slashes and no trailing one, so two spellings of one directory compare equal.
     *
     * @param  string  $path  What to normalize.
     */
    public static function normalize(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    /**
     * The Composer project a single-directory install lives in, or null when this is not one.
     *
     * A single-directory install is `composer require robot-council/cli` in a project of its own,
     * which puts this code at `<project>/vendor/robot-council/cli`.
     *
     * @param  string  $basePath  This version's own directory.
     */
    public static function projectOf(string $basePath): ?string
    {
        $basePath = self::normalize($basePath);
        $vendor = \dirname($basePath, 2);

        return basename($vendor) === 'vendor' && basename(\dirname($basePath)) === 'robot-council'
            ? \dirname($vendor)
            : null;
    }

    /**
     * The complete versions under a root, oldest first.
     *
     * Only what the launcher could run: a directory it would skip is neither counted as the newest
     * nor removed, because a cleanup that counted one once removed every version that could run.
     *
     * @param  string  $root  The versioned install's root.
     * @return list<string>
     */
    public static function versions(string $root): array
    {
        self::selection();

        $versions = [];

        foreach (is_dir($root.'/versions') ? (scandir($root.'/versions') ?: []) : [] as $name) {
            if (robot_council_is_version($name) && robot_council_complete($root.'/versions/'.$name)) {
                $versions[] = $name;
            }
        }

        usort($versions, static fn (string $a, string $b): int => version_compare(ltrim($a, 'v'), ltrim($b, 'v')));

        return $versions;
    }

    /**
     * Whether a running process holds this version.
     *
     * **A lock, not a process listing, and the same answer on every platform.** Every process the
     * launcher starts holds a shared lock on the version's `.in-use` for its whole life, so an
     * exclusive lock that cannot be taken is a version something is running. Windows would also
     * refuse to delete its files, but macOS and Linux would not: they let a running bridge's files
     * be removed under it, and it then fails the next time it loads a class it had not loaded.
     *
     * @param  string  $directory  One version's directory.
     */
    public static function inUse(string $directory): bool
    {
        $handle = @fopen($directory.'/'.self::IN_USE, 'c');

        if ($handle === false) {
            // Unreadable is not proof of idle. Leave it for a later run rather than guess.
            return true;
        }

        try {
            $free = flock($handle, LOCK_EX | LOCK_NB);

            if ($free) {
                flock($handle, LOCK_UN);
            }

            return ! $free;
        } finally {
            fclose($handle);
        }
    }

    /**
     * Remove every complete version but the chosen one, the newest and the one running now, when
     * nothing holds it; and any staging directory an interrupted upgrade left behind.
     *
     * A version still in use is left, and a later run removes it once nothing does.
     *
     * @param  string  $root  The versioned install's root.
     * @param  string|null  $running  This process's own version directory, which is never removed.
     * @return array{removed: list<string>, kept: list<string>, failed: list<string>}
     */
    public static function removeUnused(string $root, ?string $running = null): array
    {
        self::selection();

        $versions = self::versions($root);
        $keep = array_filter([robot_council_chosen($root), robot_council_newest($root.'/versions')]);
        $removed = [];
        $kept = [];
        $failed = [];

        foreach ($versions as $version) {
            $directory = $root.'/versions/'.$version;

            if (\in_array($version, $keep, true)) {
                continue;
            }

            if (($running !== null && self::normalize((string) realpath($directory)) === self::normalize((string) realpath($running))) || self::inUse($directory)) {
                $kept[] = $version;

                continue;
            }

            self::delete($directory);

            // Reported from what is on disk, not from having tried: a file something holds is not
            // deleted, and "removed" would then be false.
            if (is_dir($directory)) {
                $failed[] = $version;
            } else {
                $removed[] = $version;
            }
        }

        self::removeStaleStaging($root);

        return ['removed' => $removed, 'kept' => $kept, 'failed' => $failed];
    }

    /**
     * Remove staging directories older than any install could still be using.
     *
     * An upgrade killed partway -- a session closed, a timeout -- leaves `versions/.installing-*`
     * behind, and nothing else would ever remove it. One younger than twice the install timeout may
     * belong to an upgrade still running, and is left.
     *
     * @param  string  $root  The versioned install's root.
     * @param  int  $olderThan  How many seconds old a staging directory must be.
     */
    public static function removeStaleStaging(string $root, int $olderThan = 1200): void
    {
        foreach (glob($root.'/versions/.installing-*', GLOB_ONLYDIR) ?: [] as $staging) {
            $modified = filemtime($staging);

            if ($modified !== false && $modified < time() - $olderThan) {
                self::delete($staging);
            }
        }
    }

    /**
     * Put the launcher in `bin/`, and bring a file up to date where it differs and can be replaced.
     *
     * **A routine upgrade leaves the launcher alone**: it changes rarely, so its files are almost
     * always identical and untouched. One that differs is replaced by writing beside it and renaming
     * over it, which is atomic where it works; on Windows, where the file may be open because this
     * very command was started through it, the rename fails and the old file stays, to be replaced
     * by a later upgrade.
     *
     * @param  string  $root  The versioned install's root.
     * @param  string  $from  The `launcher/` directory of the version providing it.
     * @return array{written: list<string>, left: list<string>} Files written or updated, and ones that could not be.
     */
    public static function installLauncher(string $root, string $from): array
    {
        if (! is_dir($root.'/bin')) {
            mkdir($root.'/bin', 0o755, true);
        }

        $written = [];
        $left = [];

        foreach (self::LAUNCHER as $file) {
            $source = $from.'/'.$file;
            $target = $root.'/bin/'.$file;

            if (! is_file($source) || (is_file($target) && hash_file('sha256', $target) === hash_file('sha256', $source))) {
                continue;
            }

            $staged = $target.'.new';

            if (! @copy($source, $staged)) {
                $left[] = $file;

                continue;
            }

            if ($file === 'robot-council') {
                @chmod($staged, 0o755);
            }

            if (@rename($staged, $target)) {
                $written[] = $file;
            } else {
                @unlink($staged);
                $left[] = $file;
            }
        }

        return ['written' => $written, 'left' => $left];
    }

    /**
     * Load the launcher's own selection functions, which decide what counts as a version.
     */
    private static function selection(): void
    {
        require_once \dirname(__DIR__, 2).'/launcher/select.php';
    }

    /**
     * Remove a directory and everything under it.
     *
     * @param  string  $directory  What to remove.
     */
    public static function delete(string $directory): void
    {
        // A link is removed, never followed: its target is not this install's to delete.
        if (is_link($directory)) {
            @unlink($directory);

            return;
        }

        if (! is_dir($directory)) {
            return;
        }

        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($entries as $entry) {
            if (! $entry instanceof SplFileInfo) {
                continue;
            }

            if ($entry->isDir() && ! $entry->isLink()) {
                @rmdir($entry->getPathname());

                continue;
            }

            // Read-only files -- git's objects, and some vendored ones -- refuse `unlink()` on
            // Windows until their write bit is back.
            @chmod($entry->getPathname(), 0o666);
            @unlink($entry->getPathname());
        }

        @rmdir($directory);
    }
}
