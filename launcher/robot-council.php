<?php

declare(strict_types=1);

/**
 * The `robot-council` on `PATH` for a versioned install: runs the newest installed version.
 *
 * **Why a launcher at all** (#280). Windows refuses to delete a file a running process holds open,
 * and every running bridge holds its install's files open, so upgrading a single-directory install
 * in place failed partway and left it broken. Each version is installed into its own directory
 * under `versions/` instead, and this file -- which a routine upgrade never replaces -- picks the
 * newest one each time a process starts. A running process keeps the version it started with.
 *
 * **In process, not as a child.** The chosen version's `entry.php` is required here, so a harness's
 * stdin, stdout and signals reach the command line directly.
 *
 * **It marks the version in use for its whole life**, with a shared lock on `versions/<v>/.in-use`,
 * so `robot-council upgrade` can tell which old versions nothing is running before it removes one.
 */
require __DIR__.'/select.php';

$robotCouncilRoot = dirname(__DIR__);
$robotCouncilVersion = robot_council_newest($robotCouncilRoot.'/versions');

if ($robotCouncilVersion === null) {
    fwrite(STDERR, 'robot-council: no version is installed under '.$robotCouncilRoot.'/versions. Run `robot-council upgrade` from an existing install, or see the README.'.PHP_EOL);

    exit(1);
}

$robotCouncilDirectory = $robotCouncilRoot.'/versions/'.$robotCouncilVersion;

// Held in a global, so the handle -- and with it the lock -- lives until the process exits.
$GLOBALS['robot_council_in_use'] = fopen($robotCouncilDirectory.'/.in-use', 'c');

if ($GLOBALS['robot_council_in_use'] !== false) {
    flock($GLOBALS['robot_council_in_use'], LOCK_SH);
}

// The name help text shows, which would otherwise be this file's.
if (! defined('ARTISAN_BINARY')) {
    define('ARTISAN_BINARY', 'robot-council');
}

require $robotCouncilDirectory.'/entry.php';
