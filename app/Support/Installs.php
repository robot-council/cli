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
        $parent = \dirname($basePath);

        return basename($parent) === 'versions' && is_dir(\dirname($parent).'/bin') ? \dirname($parent) : null;
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
        $vendor = \dirname($basePath, 2);

        return basename($vendor) === 'vendor' && basename(\dirname($basePath)) === 'robot-council'
            ? \dirname($vendor)
            : null;
    }

    /**
     * The installed versions under a root, oldest first.
     *
     * @param  string  $root  The versioned install's root.
     * @return list<string>
     */
    public static function versions(string $root): array
    {
        $versions = [];

        foreach (is_dir($root.'/versions') ? (scandir($root.'/versions') ?: []) : [] as $name) {
            if (preg_match('/^v?\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/D', $name) === 1 && is_dir($root.'/versions/'.$name)) {
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
     * Remove every version but the newest and the one running now, when nothing holds it.
     *
     * A version still in use is left, and a later run removes it once nothing does.
     *
     * @param  string  $root  The versioned install's root.
     * @param  string|null  $running  This process's own version directory, which is never removed.
     * @return array{removed: list<string>, kept: list<string>} What was removed, and what was in use.
     */
    public static function removeUnused(string $root, ?string $running = null): array
    {
        $versions = self::versions($root);
        $newest = array_pop($versions);
        $removed = [];
        $kept = [];

        foreach ($versions as $version) {
            $directory = $root.'/versions/'.$version;

            if ($newest === null || ($running !== null && realpath($directory) === realpath($running)) || self::inUse($directory)) {
                $kept[] = $version;

                continue;
            }

            self::delete($directory);
            $removed[] = $version;
        }

        return ['removed' => $removed, 'kept' => $kept];
    }

    /**
     * Put the launcher in `bin/`, unless one is already there.
     *
     * **Never replaced by a routine upgrade**: a launcher already in place is left, because on
     * Windows the one running this very command may be holding it open.
     *
     * @param  string  $root  The versioned install's root.
     * @param  string  $from  The `launcher/` directory of the version providing it.
     * @return list<string> The files written.
     */
    public static function installLauncher(string $root, string $from): array
    {
        if (! is_dir($root.'/bin')) {
            mkdir($root.'/bin', 0o755, true);
        }

        $written = [];

        foreach (self::LAUNCHER as $file) {
            // A file already there is left; one the providing version lacks cannot be put there.
            if (is_file($root.'/bin/'.$file) || ! is_file($from.'/'.$file)) {
                continue;
            }

            copy($from.'/'.$file, $root.'/bin/'.$file);

            if ($file === 'robot-council') {
                @chmod($root.'/bin/'.$file, 0o755);
            }

            $written[] = $file;
        }

        return $written;
    }

    /**
     * Remove a directory and everything under it.
     *
     * @param  string  $directory  What to remove.
     */
    public static function delete(string $directory): void
    {
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
