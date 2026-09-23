<?php

declare(strict_types=1);

/**
 * What the checkout a bridge runs in says about itself.
 *
 * Two halves are tested differently on purpose. Reducing a remote to `owner/name`, and reading a
 * work location out of the directory git reports, are pure and are asserted against every shape
 * directly. Whether git is asked correctly is asserted against real repositories built in a
 * temporary directory, because a fake would only prove that this file agrees with itself -- and
 * the one defect found here so far was in what git was asked, which no fake would have caught.
 *
 * @command  vendor/bin/pest --compact tests/Feature/CheckoutTest.php
 */

use App\Support\Checkout;
use Illuminate\Support\Facades\Process;

/**
 * Make a directory nothing else is using.
 */
function scratchDirectory(): string
{
    $path = sys_get_temp_dir().'/rc-checkout-'.bin2hex(random_bytes(6));

    mkdir($path, 0777, true);

    return str_replace('\\', '/', $path);
}

/**
 * Remove a directory tree, so a run leaves nothing behind.
 *
 * **The `chmod` is load-bearing, and its absence is invisible.** Git writes loose objects
 * read-only -- mode 444 -- so `unlink()` refuses them on Windows and the tree survives. Every run
 * then leaves a repository in the temporary directory, and nothing reports it because the removal
 * is suppressed and the test has already passed. Measured: 36 of them accumulated before this was
 * noticed, each holding a `.git/objects` entry with no write bit. On Windows `chmod` toggles the
 * read-only attribute, which is exactly the bit in the way.
 */
function removeDirectory(string $path): void
{
    if (! is_dir($path)) {
        return;
    }

    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $full = $path.'/'.$entry;

        if (is_dir($full) && ! is_link($full)) {
            removeDirectory($full);

            continue;
        }

        @chmod($full, 0666);
        @unlink($full);
    }

    @rmdir($path);
}

/**
 * Run one git command in a directory, failing the test if it does not succeed.
 *
 * @param  string  $path  Where to run it.
 * @param  list<string>  $arguments  What to pass git.
 */
function runGit(string $path, array $arguments): void
{
    Process::path($path)->timeout(30)->run(array_merge(['git'], $arguments))->throw();
}

/**
 * A repository with one commit and, optionally, an `origin`.
 */
function makeRepository(string $path, ?string $origin = null): void
{
    runGit($path, ['init', '-q', '.']);
    runGit($path, ['-c', 'user.email=t@example.com', '-c', 'user.name=Test', 'commit', '-q', '--allow-empty', '-m', 'init']);

    if ($origin !== null) {
        runGit($path, ['remote', 'add', 'origin', $origin]);
    }
}

it('reduces every remote form to owner and name', function (string $remote, ?string $expected): void {
    expect(Checkout::repositoryFromRemote($remote))->toBe($expected);
})->with([
    'ssh shorthand with .git' => ['git@github.com:UAMS-Web/uams-statamic.git', 'UAMS-Web/uams-statamic'],
    'ssh shorthand without .git' => ['git@github.com:UAMS-Web/uams-statamic', 'UAMS-Web/uams-statamic'],
    'ssh url with .git' => ['ssh://git@github.com/UAMS-Web/uams-statamic.git', 'UAMS-Web/uams-statamic'],
    'https with .git' => ['https://github.com/UAMS-Web/uams-statamic.git', 'UAMS-Web/uams-statamic'],
    'https without .git' => ['https://github.com/UAMS-Web/uams-statamic', 'UAMS-Web/uams-statamic'],
    'https with a user in front of the host' => ['https://josh@github.com/UAMS-Web/uams-statamic.git', 'UAMS-Web/uams-statamic'],
    'git scheme' => ['git://github.com/UAMS-Web/uams-statamic.git', 'UAMS-Web/uams-statamic'],
    'a trailing slash' => ['https://github.com/UAMS-Web/uams-statamic/', 'UAMS-Web/uams-statamic'],
    'www is the same host' => ['https://www.github.com/UAMS-Web/uams-statamic', 'UAMS-Web/uams-statamic'],
    'the host is matched without regard to case' => ['git@GitHub.com:UAMS-Web/uams-statamic.git', 'UAMS-Web/uams-statamic'],
    'a name that itself ends in git is not truncated' => ['https://github.com/owner/not-dot-git', 'owner/not-dot-git'],
    'surrounding whitespace, as a trailing newline would leave' => ["  git@github.com:a/b.git\n", 'a/b'],
]);

it('proposes nothing rather than something wrong', function (string $remote): void {
    expect(Checkout::repositoryFromRemote($remote))->toBeNull();
})->with([
    'a host that is not GitHub' => ['git@gitlab.com:UAMS-Web/uams-statamic.git'],
    'a self-hosted host that merely contains the name' => ['https://github.example.com/UAMS-Web/uams-statamic.git'],
    'a host that only ends with the name' => ['https://notgithub.com/a/b.git'],
    'a path with too many segments' => ['https://github.com/UAMS-Web/uams-statamic/tree/main'],
    'a path naming an owner but no repository' => ['https://github.com/UAMS-Web'],
    'a local path, which is a legal remote' => ['/srv/git/uams-statamic.git'],
    'empty' => [''],
    'whitespace only' => ['   '],
    // Reduced to the charset these would become real repositories that are not this one, so they
    // are refused rather than cleaned up.
    'an owner carrying a character GitHub never issues' => ['https://github.com/UAMS Web/uams-statamic'],
    'a name carrying one' => ['git@github.com:UAMS-Web/uams~statamic.git'],
    // The service refuses a segment that begins with a hyphen or is nothing but dots. A repository
    // is rejected rather than normalized, because a changed name is a different repository.
    'an owner beginning with a hyphen, which reads as a flag' => ['git@github.com:-leading-hyphen/name.git'],
    'a name beginning with a hyphen' => ['git@github.com:owner/-leading-hyphen.git'],
    'a traversal-shaped owner' => ['git@github.com:../evil.git'],
    'a name left as nothing but dots once the suffix is stripped' => ['git@github.com:owner/...git'],
]);

it('reads the work location from the absolute git directory', function (string $gitDir, ?string $expected): void {
    expect(Checkout::locationFromGitDir($gitDir))->toBe($expected);
})->with([
    'a main checkout' => ['/repo/.git', Checkout::PRIMARY],
    'a relative answer, as the root of a main checkout gives' => ['.git', Checkout::PRIMARY],
    'a linked worktree is named by its last segment' => ['/repo/.git/worktrees/uams-statamic-a', 'uams-statamic-a'],
    'backslashes are read as separators' => ['C:\\repo\\.git\\worktrees\\ci', 'ci'],
    'a trailing slash does not hide the name' => ['/repo/.git/worktrees/ci/', 'ci'],
    'a submodule is the main copy of itself' => ['/repo/.git/modules/thing', Checkout::PRIMARY],
    // A repository that merely lives under a directory called `worktrees` is not a worktree: what
    // decides it is the segment before the LAST one, which here is the repository's own directory.
    'a repository stored under a directory named worktrees' => ['/x/worktrees/foo/.git', Checkout::PRIMARY],
    // The service matches a work location against `/^(?!\.+$)[a-z0-9_.][a-z0-9._-]*$/D`, so a
    // capital in a directory name would be refused with a 422 rather than stored.
    'an upper-case directory name becomes the lower-case label the service accepts' => ['/repo/.git/worktrees/Feature-A', 'feature-a'],
    'a space is dropped rather than refused' => ['/repo/.git/worktrees/my tree', 'mytree'],
    // A location is normalized where a repository would be rejected: the operator who named the
    // directory still recognizes the label, and no other place is named by mistake.
    'a leading hyphen is dropped, since the service refuses one' => ['/repo/.git/worktrees/-wip', 'wip'],
    'a lone hyphen leaves nothing to propose' => ['/repo/.git/worktrees/-', null],
    'a name that is nothing but dots proposes nothing' => ['/repo/.git/worktrees/...', null],
    'a leading dot is kept, which the service allows' => ['/repo/.git/worktrees/.tmp', '.tmp'],
    'empty' => ['', null],
]);

it('truncates a work location to the length that may be sent', function (): void {
    $long = str_repeat('a', Checkout::MAX_LOCATION + 20);

    expect(Checkout::locationFromGitDir('/repo/.git/worktrees/'.$long))
        ->toHaveLength(Checkout::MAX_LOCATION);
});

it('derives the repository from a real checkout, in either remote form', function (string $origin): void {
    $path = scratchDirectory();

    try {
        makeRepository($path, $origin);

        expect(Checkout::repository($path))->toBe('UAMS-Web/uams-statamic');
    } finally {
        removeDirectory($path);
    }
})->with([
    'ssh' => ['git@github.com:UAMS-Web/uams-statamic.git'],
    'https' => ['https://github.com/UAMS-Web/uams-statamic.git'],
    'https without the suffix' => ['https://github.com/UAMS-Web/uams-statamic'],
]);

it('calls the main checkout primary and a linked worktree by its own name', function (): void {
    $root = scratchDirectory();

    try {
        $main = $root.'/main';
        mkdir($main, 0777, true);
        makeRepository($main, 'git@github.com:UAMS-Web/uams-statamic.git');

        $linked = $root.'/uams-statamic-a';

        runGit($main, ['worktree', 'add', '-q', $linked, '-b', 'wt-a']);

        expect(Checkout::workLocation($main))->toBe(Checkout::PRIMARY)
            ->and(Checkout::workLocation($linked))->toBe('uams-statamic-a')
            // The worktree is a working copy of the same repository, and says so.
            ->and(Checkout::repository($linked))->toBe('UAMS-Web/uams-statamic');
    } finally {
        removeDirectory($root);
    }
});

it('answers the same from a subdirectory as from the root of a checkout', function (): void {
    // A bridge is started by a harness and is not guaranteed to run at the root of the checkout.
    // An earlier version of this compared `--git-dir` against `--git-common-dir`, which from a
    // subdirectory of a main checkout answers an absolute path and a relative one -- so the two
    // differed, and a main checkout was reported as having no work location at all.
    $root = scratchDirectory();

    try {
        $main = $root.'/main';
        mkdir($main.'/app/Support', 0777, true);
        makeRepository($main, 'git@github.com:UAMS-Web/uams-statamic.git');

        $linked = $root.'/uams-statamic-a';

        runGit($main, ['worktree', 'add', '-q', $linked, '-b', 'wt-a']);

        mkdir($linked.'/app/Support', 0777, true);

        expect(Checkout::workLocation($main.'/app/Support'))->toBe(Checkout::PRIMARY)
            ->and(Checkout::workLocation($linked.'/app/Support'))->toBe('uams-statamic-a')
            ->and(Checkout::repository($main.'/app/Support'))->toBe('UAMS-Web/uams-statamic')
            ->and(Checkout::repository($linked.'/app/Support'))->toBe('UAMS-Web/uams-statamic');
    } finally {
        removeDirectory($root);
    }
});

it('says nothing, rather than failing, where there is nothing to say', function (): void {
    $bare = scratchDirectory();
    $noOrigin = scratchDirectory();

    try {
        makeRepository($noOrigin);

        // A directory that is not a repository at all.
        expect(Checkout::repository($bare))->toBeNull()
            ->and(Checkout::workLocation($bare))->toBeNull()
            // A repository with no `origin`. The location is still knowable, and is still given.
            ->and(Checkout::repository($noOrigin))->toBeNull()
            ->and(Checkout::workLocation($noOrigin))->toBe(Checkout::PRIMARY);
    } finally {
        removeDirectory($bare);
        removeDirectory($noOrigin);
    }
});

it('reads a checkout whose path contains a space', function (): void {
    // This repository is checked out at `D:\GitHub Repos\robot-council\cli` on at least one
    // machine on the fleet, so a path with a space in it is the normal case rather than an exotic
    // one. Arguments are passed to git as an array and never through a shell, and this says so.
    $root = scratchDirectory();
    $spaced = $root.'/GitHub Repos/uams statamic';

    try {
        mkdir($spaced, 0777, true);
        makeRepository($spaced, 'git@github.com:UAMS-Web/uams-statamic.git');

        expect(Checkout::repository($spaced))->toBe('UAMS-Web/uams-statamic')
            ->and(Checkout::workLocation($spaced))->toBe(Checkout::PRIMARY);
    } finally {
        removeDirectory($root);
    }
});

it('says nothing when the directory does not exist', function (): void {
    $missing = sys_get_temp_dir().'/rc-checkout-absent-'.bin2hex(random_bytes(6));

    expect($missing)->not->toBeDirectory()
        ->and(Checkout::repository($missing))->toBeNull()
        ->and(Checkout::workLocation($missing))->toBeNull();
});

it('agrees with the bounds core actually enforces', function (): void {
    // Written out rather than imported, because `core` is a separate package this one does not
    // depend on. Read from its `Support\WorkIdentity` at `session-work-location`. If these drift,
    // a session start fails with a 422 that names no field, on the developer's machine.
    expect(Checkout::MAX_REPOSITORY)->toBe(140)
        ->and(Checkout::MAX_LOCATION)->toBe(32);
});

it('proposes only values the service will accept', function (): void {
    // Stronger than pinning the two numbers: these are the service's own patterns, and what is
    // asserted is that anything this class proposes satisfies them. A derived value the service
    // refuses is a defect here, since the developer never chose the charset.
    // Copied from `robot-council/core`'s `Support\WorkIdentity` at `75f7562`. **These tightened
    // between that pull request's draft and its merge**, adding the leading-hyphen and all-dots
    // rules, and an earlier version of this test embedded the draft's looser pair -- so it passed
    // while this class proposed eight values the service would refuse. The revision is named so
    // the next reader can tell whether it has moved again.
    $repositoryPattern = '/^(?!\.+\/)[A-Za-z0-9_.][A-Za-z0-9._-]*\/(?!\.+$)[A-Za-z0-9_.][A-Za-z0-9._-]*$/D';
    $locationPattern = '/^(?!\.+$)[a-z0-9_.][a-z0-9._-]*$/D';

    $repositories = [
        'git@github.com:UAMS-Web/uams-statamic.git',
        'https://github.com/UAMS-Web/uams-statamic',
        'https://www.github.com/Owner/Name.Dot_Under-Dash',
        'git@github.com:_under/score.git',
        'git@github.com:.dot/.dot.git',
        // Adversarial, and the reason this loop skips nulls: proposing nothing is always allowed,
        // so what is asserted is that anything actually proposed is acceptable to the service.
        'git@github.com:-leading-hyphen/name.git',
        'git@github.com:owner/-leading-hyphen.git',
        'git@github.com:../evil.git',
        'git@github.com:owner/...git',
        'git@github.com:UAMS Web/uams-statamic.git',
    ];

    foreach ($repositories as $remote) {
        $repository = Checkout::repositoryFromRemote($remote);

        if ($repository === null) {
            continue;
        }

        expect($repository)->toMatch($repositoryPattern)
            ->and(mb_strlen($repository))->toBeLessThanOrEqual(Checkout::MAX_REPOSITORY);
    }

    $gitDirs = [
        '/repo/.git',
        '/repo/.git/worktrees/uams-statamic-a',
        '/repo/.git/worktrees/Feature-A',
        '/repo/.git/worktrees/-wip',
        '/repo/.git/worktrees/.tmp',
        '/repo/.git/worktrees/_scratch',
        '/repo/.git/worktrees/'.str_repeat('a', Checkout::MAX_LOCATION + 20),
        '/repo/.git/worktrees/-wip',
        '/repo/.git/worktrees/-',
        '/repo/.git/worktrees/...',
        '/repo/.git/worktrees/..',
        '/repo/.git/worktrees/my tree',
    ];

    foreach ($gitDirs as $gitDir) {
        $location = Checkout::locationFromGitDir($gitDir);

        if ($location === null) {
            continue;
        }

        expect($location)->toMatch($locationPattern)
            ->and(mb_strlen($location))->toBeLessThanOrEqual(Checkout::MAX_LOCATION);
    }
});

it('bounds every git invocation', function (): void {
    // The value matters less than its existence: this runs on the path to joining, and a git that
    // hangs must not take the bridge with it. Pinned so that removing the bound fails here.
    expect(Checkout::TIMEOUT_SECONDS)->toBeGreaterThan(0)
        ->and(Checkout::TIMEOUT_SECONDS)->toBeLessThanOrEqual(5);
});
