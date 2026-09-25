<?php

declare(strict_types=1);

/**
 * Which version this command line reports, and why it needs two sources to say it.
 *
 * **The bug this covers only appears when the package is installed**, which is why it survived
 * every run of this suite until the package was published and installed for the first time
 * (#100). A checkout has a `.git` directory, so Laravel Zero's `git.version` reads the tag and is
 * right; a Composer install has none, so it answers `unreleased` to somebody who just installed a
 * tagged release.
 *
 * `Version::resolve()` is the decision, separated from the sources so these tests can cover it.
 * Every input to `current()` comes from global state a test cannot arrange -- a Composer
 * autoloader, a container binding, a working tree -- so a test of `current()` alone could only
 * ever exercise the shape the suite runs in, which is the checkout, which is the case that already
 * worked.
 *
 * @command  vendor/bin/pest --compact tests/Feature/VersionTest.php
 */

use App\Support\Version;

it('reports the tag when run from a checkout of this repository', function (): void {
    // The root package being this package is what identifies a checkout. Composer would answer
    // `dev-main` here -- the branch, not the tag -- so the git service wins.
    expect(Version::resolve(Version::PACKAGE, 'dev-main', 'v0.2.0'))->toBe('v0.2.0');
});

it('reports what Composer resolved when installed as a dependency', function (): void {
    // **The defect in one line.** The git service has no working tree to read and says
    // `unreleased`; Composer knows exactly which version it installed.
    expect(Version::resolve('__root__', 'v0.2.0', 'unreleased'))->toBe('v0.2.0');
});

it('prefers git in a checkout even when Composer has an answer, and Composer otherwise', function (): void {
    // The two are given the same pair of values and must choose differently, so this fails if the
    // root package stops deciding anything.
    expect(Version::resolve(Version::PACKAGE, 'v9.9.9', 'v0.2.0'))->toBe('v0.2.0')
        ->and(Version::resolve('__root__', 'v9.9.9', 'v0.2.0'))->toBe('v9.9.9');
});

it('names any host project as an install, not just the global one', function (): void {
    // `__root__` is what Composer calls the root of a global install. A host application has its
    // own name, and it is just as much an install as the global one.
    expect(Version::resolve('acme/site', 'v0.2.0', 'unreleased'))->toBe('v0.2.0');
});

it('falls back to git when installed but Composer has nothing to say', function (): void {
    // A shape that should not occur, since being installed is how Composer knows the version. It
    // answers with the worse source rather than with nothing.
    expect(Version::resolve('__root__', null, 'v0.2.0'))->toBe('v0.2.0');
});

it('answers a word rather than a blank when neither source can', function (): void {
    // An empty version renders as a gap where a word belongs, in output a person reads to open a
    // bug report.
    expect(Version::resolve('__root__', null, null))->toBe(Version::UNKNOWN)
        ->and(Version::resolve(Version::PACKAGE, null, null))->toBe(Version::UNKNOWN)
        // Empty and whitespace-only are treated as nothing, not reported as themselves.
        ->and(Version::resolve('__root__', '', 'v0.2.0'))->toBe('v0.2.0')
        ->and(Version::resolve('__root__', '   ', 'v0.2.0'))->toBe('v0.2.0')
        ->and(Version::resolve(Version::PACKAGE, 'v0.2.0', '  '))->toBe(Version::UNKNOWN);
});

it('answers for an unknown root package the way it answers for a host project', function (): void {
    // Composer's root package has no name in some arrangements. Anything that is not this package
    // is an install.
    expect(Version::resolve(null, 'v0.2.0', 'unreleased'))->toBe('v0.2.0');
});

it('reports something usable from the running application, whatever shape it is in', function (): void {
    // The wiring, as opposed to the decision. In this suite that is always the checkout, so this
    // asserts the contract rather than a particular string: never empty, and a trimmed word.
    $version = Version::current();

    expect($version)->not->toBeEmpty()
        ->and($version)->toBe(trim($version))
        ->and(config('app.version'))->toBe($version);
});

it('reports a versioned install by the directory it was installed into, which nothing else knows', function (): void {
    // #280, measured on the released v0.4.18: `composer create-project` makes this package the
    // root, so Composer reports `dev-main`-like nothing and the archive has no `.git`, and the
    // launcher printed `robot-council unreleased`.
    expect(Version::resolve(Version::PACKAGE, null, Version::UNKNOWN, '0.4.19'))->toBe('v0.4.19')
        ->and(Version::resolve(Version::PACKAGE, null, Version::UNKNOWN, 'v0.4.19'))->toBe('v0.4.19')

        // And without a layout, the decision is what it was.
        ->and(Version::resolve(Version::PACKAGE, null, Version::UNKNOWN))->toBe(Version::UNKNOWN)
        ->and(Version::resolve('__root__', 'v0.2.0', 'unreleased'))->toBe('v0.2.0');
});
