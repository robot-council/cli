<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Process;
use Throwable;

/**
 * What the checkout a bridge is running in says about itself.
 *
 * A bridge already runs with its working directory inside a checkout, so the two things the fleet
 * wants to know about it -- which repository, and which of that repository's working copies -- are
 * readable rather than typed. Today they are hand-written into each harness config as `--project`,
 * once per worktree, and never revisited.
 *
 * **Everything here is a proposal, and none of it is trustworthy.** A derived value is the client
 * asserting something about itself, exactly as a typed one is, so neither may reach an
 * authorization decision. What changes is that the value is right by default instead of right by
 * diligence: a label nobody re-reads is the kind that is wrong for months, because nothing ever
 * contradicts it.
 *
 * **Nothing here may stop a bridge from joining.** Every failure -- no git, no repository, no
 * `origin`, a remote pointing somewhere that is not GitHub, a git that hangs -- resolves to `null`,
 * which means "propose nothing" rather than "refuse". Proposing nothing is always better than
 * proposing something wrong, because a wrong proposal is one an operator has no reason to inspect.
 */
final class Checkout
{
    /**
     * How long any one git invocation may take.
     *
     * Short deliberately. This runs on the path to joining, the answer is a convenience, and a git
     * that has not answered in two seconds is one that is not going to be worth waiting for. A
     * bridge that cannot guess still joins.
     */
    public const int TIMEOUT_SECONDS = 2;

    /**
     * What the main working copy of a repository calls itself.
     *
     * A checkout that is not a linked worktree has no name of its own -- git does not give it one
     * -- so one is supplied here rather than leaving the common case blank.
     */
    public const string PRIMARY = 'primary';

    /**
     * The longest repository this will propose, counting `owner/name` whole.
     *
     * **`robot-council/core` is the authority, and this mirrors it deliberately.** Its
     * `Support\WorkIdentity` stores a repository in 140 characters, which is GitHub's own bound:
     * an owner is at most 39 and a repository name at most 100, plus the separator. A value this
     * command line sends that the service then refuses is a bug here, not there.
     */
    public const int MAX_REPOSITORY = 140;

    /**
     * The longest work location this will propose.
     *
     * Also `robot-council/core`'s number, and much shorter than a repository: a location is a
     * label for one checkout, not a path.
     */
    public const int MAX_LOCATION = 32;

    /**
     * The only host whose remotes name a repository this fleet can talk about.
     *
     * A remote pointing anywhere else is not an error and is not coerced: it proposes nothing. An
     * enterprise or self-hosted host would need its own decision about what `owner/name` even means
     * there, and guessing would produce a well-formed wrong answer.
     *
     * @var list<string>
     */
    private const array HOSTS = ['github.com', 'www.github.com'];

    /**
     * The shape one side of `owner/name` must have.
     *
     * **`robot-council/core` is the authority, and this mirrors its `Support\WorkIdentity` at
     * `75f7562`.** A segment may not begin with a hyphen and may not be nothing but dots. Both
     * rules arrived between that pull request's draft and its merge, and both matter: a leading
     * hyphen reads as a flag to anything that later builds a command line, and `..` reads as a
     * traversal to anything that joins it to a path.
     */
    private const string SEGMENT = '/^[A-Za-z0-9_.][A-Za-z0-9._-]*$/D';

    /**
     * The shape a work location must have, which is the lower-case form of the same rule.
     */
    private const string LOCATION = '/^[a-z0-9_.][a-z0-9._-]*$/D';

    /**
     * Which GitHub repository this checkout belongs to, or null when nothing can be said.
     *
     * @param  string|null  $directory  Where to ask from; the current working directory by default.
     * @return string|null `owner/name`, or null.
     */
    public static function repository(?string $directory = null): ?string
    {
        $remote = self::git(['remote', 'get-url', 'origin'], $directory);

        return $remote === null ? null : self::repositoryFromRemote($remote);
    }

    /**
     * Which working copy of that repository this is, or null when nothing can be said.
     *
     * @param  string|null  $directory  Where to ask from; the current working directory by default.
     * @return string|null The worktree's name, `primary` for the main checkout, or null.
     */
    public static function workLocation(?string $directory = null): ?string
    {
        // `--absolute-git-dir` rather than `--git-dir`, and one value rather than two compared
        // against each other. Measured on git 2.x: from a **subdirectory** of a main checkout,
        // `--git-dir` answers an absolute path while `--git-common-dir` answers a relative one, so
        // comparing the pair as strings reports a main checkout as neither primary nor a worktree.
        // A bridge is not guaranteed to run at the root of its checkout, so that is a live case.
        $gitDir = self::git(['rev-parse', '--absolute-git-dir'], $directory);

        return $gitDir === null ? null : self::locationFromGitDir($gitDir);
    }

    /**
     * Reduce one remote URL to `owner/name`.
     *
     * Kept separate from the git invocation so every remote form can be asserted without a
     * repository to hold it. The forms in the wild are the SSH shorthand `git@host:owner/name.git`,
     * a real URL with any of the `ssh`, `git`, `http` and `https` schemes, either with or without
     * the `.git` suffix, and either with or without a user in front of the host.
     *
     * @param  string  $remote  What `git remote get-url origin` said.
     * @return string|null `owner/name`, or null when this is not a GitHub repository.
     */
    public static function repositoryFromRemote(string $remote): ?string
    {
        $remote = trim($remote);

        if ($remote === '') {
            return null;
        }

        // `git@github.com:owner/name.git` is not a URL -- it has no scheme, and its colon separates
        // a host from a path rather than a port -- so `parse_url` reads the whole thing as a path.
        if (! str_contains($remote, '://') && preg_match('/^(?:[^@\/]+@)?([^:\/]+):(.+)$/', $remote, $matches) === 1) {
            return self::repositoryFromParts($matches[1], $matches[2]);
        }

        $host = parse_url($remote, PHP_URL_HOST);
        $path = parse_url($remote, PHP_URL_PATH);

        if (! \is_string($host) || ! \is_string($path)) {
            return null;
        }

        return self::repositoryFromParts($host, $path);
    }

    /**
     * Decide the work location from the absolute git directory.
     *
     * Measured rather than assumed, on git 2.x, from four positions -- the root and a subdirectory
     * of each of a main checkout and a linked worktree. `--absolute-git-dir` answers the same value
     * from the root and from a subdirectory, which is what makes one value enough: a main checkout
     * answers `<repo>/.git`, and a linked worktree answers `<common>/.git/worktrees/<name>`.
     *
     * The name is taken from that path rather than from the directory's basename, because it is the
     * name git knows the worktree by, and git disambiguates a basename that collides with one it
     * already has.
     *
     * Anything that is not a linked worktree is the main working copy of whatever it is -- a plain
     * checkout, a bare repository, a submodule -- so it is `primary` rather than nothing.
     *
     * @param  string  $gitDir  What `rev-parse --absolute-git-dir` said.
     * @return string|null The location, or null when nothing usable is left.
     */
    public static function locationFromGitDir(string $gitDir): ?string
    {
        $gitDir = self::normalize($gitDir);

        if ($gitDir === '') {
            return null;
        }

        $segments = explode('/', $gitDir);
        $name = array_pop($segments);
        $parent = array_pop($segments);

        // `$parent` is null when the path has only one segment, which is not `worktrees` either.
        if ($parent !== 'worktrees' || $name === '') {
            return self::PRIMARY;
        }

        return self::reduce($name, self::MAX_LOCATION);
    }

    /**
     * Turn a host and a path into `owner/name`, when the host is GitHub and the path is a repository.
     *
     * @param  string  $host  The remote's host.
     * @param  string  $path  The remote's path.
     * @return string|null `owner/name`, or null.
     */
    private static function repositoryFromParts(string $host, string $path): ?string
    {
        if (! \in_array(strtolower($host), self::HOSTS, true)) {
            return null;
        }

        $path = trim($path, '/');

        if (str_ends_with(strtolower($path), '.git')) {
            $path = substr($path, 0, -4);
        }

        $segments = explode('/', $path);

        // Exactly two, because `owner/name` is the whole shape. A longer path is some other GitHub
        // URL -- a tree, a pull request -- and a shorter one names no repository.
        if (\count($segments) !== 2) {
            return null;
        }

        // **Rejected rather than reduced, unlike a work location.** GitHub already confines an
        // owner and a repository name to this charset, so a segment that needs characters removed
        // did not come from a GitHub repository -- and a stripped name is a different repository
        // that may well exist. A wrong proposal is worse than none, because nothing about it
        // invites an operator to look.
        if (! self::isIdentifier($segments[0]) || ! self::isIdentifier($segments[1])) {
            return null;
        }

        $repository = $segments[0].'/'.$segments[1];

        return \strlen($repository) > self::MAX_REPOSITORY ? null : $repository;
    }

    /**
     * Run one git command, and answer null for every way it can fail.
     *
     * @param  list<string>  $arguments  What to pass git.
     * @param  string|null  $directory  Where to run it.
     * @return string|null Its trimmed output, or null.
     */
    private static function git(array $arguments, ?string $directory): ?string
    {
        $directory ??= getcwd();

        if (! \is_string($directory) || $directory === '') {
            return null;
        }

        try {
            $result = Process::path($directory)
                ->timeout(self::TIMEOUT_SECONDS)
                ->run(array_merge(['git'], $arguments));

            if (! $result->successful()) {
                return null;
            }

            $output = trim($result->output());

            return $output === '' ? null : $output;
        } catch (Throwable) {
            // A timeout, a missing git, a directory that has stopped existing. None of them are
            // this bridge's problem, and none of them may keep it from joining.
            return null;
        }
    }

    /**
     * One path, with separators and trailing slashes made comparable.
     *
     * Windows git answers with forward slashes, but a path that reached here another way may not,
     * and two spellings of one directory comparing unequal would report a main checkout as a
     * worktree with no name.
     */
    private static function normalize(string $path): string
    {
        return rtrim(str_replace('\\', '/', trim($path)), '/');
    }

    /**
     * Whether one segment is already a name GitHub could have issued.
     *
     * @param  string  $value  The segment as the remote spelled it.
     */
    private static function isIdentifier(string $value): bool
    {
        return preg_match(self::SEGMENT, $value) === 1 && ! self::isAllDots($value);
    }

    /**
     * Whether a value is nothing but dots.
     *
     * `.` and `..` are directory entries rather than names, and a repository segment spelled `..`
     * reads as a traversal to anything that later joins it to a path. The service refuses them,
     * and a value refused there is one that should never have left here.
     *
     * @param  string  $value  The segment to judge.
     */
    private static function isAllDots(string $value): bool
    {
        return preg_match('/^\.+$/D', $value) === 1;
    }

    /**
     * Reduce one segment to what may be sent, or null when nothing usable is left.
     *
     * Used for a work location, which is a directory somebody named locally and may therefore
     * contain anything. A reduced directory name is still recognizable to the operator who named
     * it, and it stays a proposal they can correct.
     *
     * @param  string  $value  The raw segment.
     * @param  int  $limit  The longest it may be.
     */
    private static function reduce(string $value, int $limit): ?string
    {
        // **Lower-cased, unlike a repository, because the service's charsets differ.**
        // `robot-council/core`'s `Support\WorkIdentity` matches a work location against
        // `/^[a-z0-9._-]+$/D` and a repository against `/^[A-Za-z0-9._-]+\/[A-Za-z0-9._-]+$/D`.
        // The difference is deliberate there: a repository name is GitHub's and keeps its case,
        // while a location is a label this fleet compares across machines, and two spellings would
        // read as two places. A worktree directory named `Feature-A` would otherwise derive a value
        // the service refuses with a 422, which is the developer discovering a charset they never
        // agreed to -- the failure `Support\MachineIdentity` exists to prevent.
        $reduced = preg_replace('/[^a-z0-9._-]/', '', strtolower($value)) ?? '';

        // A leading hyphen is dropped rather than refused, which is the same normalizing this
        // already does to a space: `-wip` is plainly meant to be `wip`, and the operator who named
        // the directory still recognizes it. A repository segment is rejected instead, because its
        // name is GitHub's and a normalized one names a different repository.
        $reduced = ltrim($reduced, '-');

        $reduced = substr($reduced, 0, $limit);

        // Nothing but dots normalizes to nothing anybody meant, so it proposes nothing.
        if ($reduced === '' || self::isAllDots($reduced) || preg_match(self::LOCATION, $reduced) !== 1) {
            return null;
        }

        return $reduced;
    }
}
