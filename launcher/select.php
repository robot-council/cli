<?php

declare(strict_types=1);

/**
 * Whether a directory holds a version the launcher can run: `entry.php` and an installed vendor.
 *
 * **One definition, read by the launcher and by `upgrade`'s cleanup alike** (#280). A cleanup that
 * counted a directory the launcher would skip once treated an empty `0.7.0/` as the newest version
 * and removed every version that could actually run.
 *
 * @param  string  $directory  One version's directory.
 */
function robot_council_complete(string $directory): bool
{
    return ! is_link($directory) && is_file($directory.'/entry.php') && is_file($directory.'/vendor/autoload.php');
}

/**
 * Whether a name is a version's, such as `0.4.17` or `0.5.0-beta.1`.
 *
 * A staging directory, which `upgrade` writes under a dot-prefixed name and renames into place when
 * it is complete, is never one; nor is a copy somebody made, such as `0.4.17.bak`.
 */
function robot_council_is_version(string $name): bool
{
    return preg_match('/^v?\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/D', $name) === 1;
}

/**
 * The newest complete version, or null when there is none.
 *
 * @param  string  $versions  The directory the versions are installed under.
 * @return string|null The chosen directory's name, such as `0.4.17`, or null.
 */
function robot_council_newest(string $versions): ?string
{
    $best = null;

    foreach (is_dir($versions) ? (scandir($versions) ?: []) : [] as $name) {
        if (! robot_council_is_version($name) || ! robot_council_complete($versions.'/'.$name)) {
            continue;
        }

        if ($best === null || version_compare(ltrim($name, 'v'), ltrim($best, 'v'), '>')) {
            $best = $name;
        }
    }

    return $best;
}

/**
 * The version the launcher runs: the pinned one when it is complete, the newest otherwise.
 *
 * **A pin is how a named version stays chosen**, including one older than the newest, which is
 * what rolling back after a bad release needs. `robot-council upgrade <version>` writes it, and
 * `robot-council upgrade` with no version removes it.
 *
 * @param  string  $root  The versioned install's root.
 * @return string|null The chosen directory's name, or null.
 */
function robot_council_chosen(string $root): ?string
{
    $pinned = is_file($root.'/pinned') ? trim((string) file_get_contents($root.'/pinned')) : '';

    if ($pinned !== '' && robot_council_is_version($pinned) && robot_council_complete($root.'/versions/'.$pinned)) {
        return $pinned;
    }

    return robot_council_newest($root.'/versions');
}
