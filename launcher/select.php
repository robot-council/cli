<?php

declare(strict_types=1);

/**
 * Which installed version the launcher runs: the newest complete one, or null when there is none.
 *
 * **Standalone, with no autoloader**, because it runs before any version's autoloader is chosen.
 * A version directory counts only when it is complete -- it holds `entry.php` and an installed
 * `vendor/autoload.php` -- so a version still being installed, which `robot-council upgrade` writes
 * under a dot-prefixed name and renames into place when it is done, is never run (#280). Versions
 * before the launcher existed carry no `entry.php`, and are never chosen either.
 *
 * @param  string  $versions  The directory the versions are installed under.
 * @return string|null The chosen directory's name, such as `0.4.17`, or null.
 */
function robot_council_newest(string $versions): ?string
{
    $best = null;

    foreach (is_dir($versions) ? (scandir($versions) ?: []) : [] as $name) {
        if (preg_match('/^v?\d+\.\d+\.\d+(?:-[0-9A-Za-z.-]+)?$/D', $name) !== 1) {
            continue;
        }

        if (! is_file($versions.'/'.$name.'/entry.php') || ! is_file($versions.'/'.$name.'/vendor/autoload.php')) {
            continue;
        }

        if ($best === null || version_compare(ltrim($name, 'v'), ltrim($best, 'v'), '>')) {
            $best = $name;
        }
    }

    return $best;
}
