<?php

declare(strict_types=1);

/**
 * The `robot-council` on `PATH` for a versioned install: runs the chosen version.
 *
 * **Why a launcher at all** (#280). Windows refuses to delete a file a running process holds open,
 * and every running bridge holds its install's files open, so upgrading a single-directory install
 * in place failed partway and left it broken. Each version is installed into its own directory
 * under `versions/` instead, and this file picks the pinned version, or the newest, each time a
 * process starts. A running process keeps the version it started with.
 *
 * **In process, not as a child.** The chosen version's `entry.php` is required here, so a harness's
 * stdin, stdout and signals reach the command line directly. `entry.php` itself marks the version
 * in use, so a version started without this launcher is marked too.
 */
require __DIR__.'/select.php';

$robotCouncilRoot = dirname(__DIR__);
$robotCouncilVersion = robot_council_chosen($robotCouncilRoot);

if ($robotCouncilVersion === null) {
    fwrite(STDERR, 'robot-council: no version is installed under '.$robotCouncilRoot.'/versions. See "Installing" in the README.'.PHP_EOL);

    exit(1);
}

// The name help text shows, which would otherwise be this file's.
if (! defined('ARTISAN_BINARY')) {
    define('ARTISAN_BINARY', 'robot-council');
}

require $robotCouncilRoot.'/versions/'.$robotCouncilVersion.'/entry.php';
