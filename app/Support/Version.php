<?php

declare(strict_types=1);

namespace App\Support;

use Composer\InstalledVersions;

/**
 * What version of this command line is running, whichever way it was installed.
 *
 * **Neither available source is right on its own, and that is the whole reason this exists.**
 * Measured on 2026-09-22, in a checkout of this repository and in a throwaway `COMPOSER_HOME`
 * holding `composer global require robot-council/cli`:
 *
 * | source | in a checkout | installed globally |
 * | --- | --- | --- |
 * | `app('git.version')` | `v0.2.0` | **`unreleased`** |
 * | `InstalledVersions::getPrettyVersion()` | **`dev-main`** | `v0.2.0` |
 *
 * Laravel Zero's `git.version` reads tags out of a working tree, and a Composer install has no
 * `.git` -- `--prefer-dist` unpacks an archive -- so it falls back to the string `unreleased`.
 * Composer knows exactly what it resolved, but in a checkout the root package *is* this package,
 * so it reports the branch rather than the tag.
 *
 * **The root package's name is what tells the two apart.** In a checkout it is
 * `robot-council/cli`; installed, it is the consuming project, which for a global install Composer
 * names `__root__`.
 *
 * Before `robot-council/cli#75` this did not matter: the documented install pinned `dev-main`, a
 * moving branch, where "unreleased" was arguably honest. Publishing changed the default to a
 * resolved tag, and the first question after installing one is which version is in hand.
 */
final class Version
{
    /**
     * This package's name, as Composer knows it.
     */
    public const string PACKAGE = 'robot-council/cli';

    /**
     * What to report when neither source can answer.
     *
     * The same word Laravel Zero uses, so nothing downstream has to learn a second one.
     */
    public const string UNKNOWN = 'unreleased';

    /**
     * The version to report.
     *
     * @return string A version string, or `UNKNOWN` when neither source can answer.
     */
    public static function current(): string
    {
        $root = InstalledVersions::getRootPackage();

        $installed = InstalledVersions::isInstalled(self::PACKAGE)
            ? InstalledVersions::getPrettyVersion(self::PACKAGE)
            : null;

        $fromGit = app('git.version');

        // `name` is declared non-nullable on Composer's own array shape, so it is read directly --
        // a guard here is dead code the analyzer refuses. `resolve()` still accepts null, because
        // it is a pure function documenting what it does with one rather than a claim that this
        // caller can produce one.
        return self::resolve($root['name'], $installed, \is_string($fromGit) ? $fromGit : null);
    }

    /**
     * Decide between the two sources.
     *
     * **Separate from `current()` so the decision can be tested without a real install.** Every
     * input here comes from global state a test cannot arrange -- a Composer autoloader, a
     * container binding, a `.git` directory -- so a function that read them directly could only be
     * tested in whichever shape the test suite happens to run in, which is the checkout, which is
     * the case that already worked.
     *
     * @param  string|null  $rootPackage  The name of the Composer root package, or null.
     * @param  string|null  $installed  What Composer resolved for this package, or null when this
     *                                  package is not among the installed ones.
     * @param  string|null  $fromGit  What the git service reports, which is `UNKNOWN` where there
     *                                is no working tree to read.
     * @return string The version to report.
     */
    public static function resolve(?string $rootPackage, ?string $installed, ?string $fromGit): string
    {
        // A checkout of this repository: the git service reads its tags, and Composer would answer
        // with the branch. Preferred even when it says `unreleased`, which is what a checkout with
        // no tags honestly is.
        if ($rootPackage === self::PACKAGE) {
            return self::nonEmpty($fromGit) ?? self::UNKNOWN;
        }

        // Installed as a dependency. Composer is the only source with an answer, because there is
        // no working tree for the git service to read.
        return self::nonEmpty($installed) ?? self::nonEmpty($fromGit) ?? self::UNKNOWN;
    }

    /**
     * A value, or null when there is nothing usable in it.
     *
     * `getPrettyVersion()` is declared nullable and an absent binding can hand back an empty
     * string, and an empty version would be reported as a blank where a word belongs.
     *
     * @param  string|null  $value  The candidate.
     * @return string|null The value, or null.
     */
    private static function nonEmpty(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : $value;
    }
}
