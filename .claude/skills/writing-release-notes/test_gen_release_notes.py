#!/usr/bin/env python3
"""
Offline tests for gen_release_notes.py: title cleanup and bucket routing. Neither calls
`git` or `gh`, so these run without a network or a checkout history.

  python3 -m unittest discover -s .claude/skills/writing-release-notes
"""
import os
import sys
import unittest

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

import gen_release_notes as g  # noqa: E402


class AcronymCasing(unittest.TestCase):
    def test_recases_a_standalone_word(self):
        self.assertEqual(g.fix_acro("raise phpstan to level max"), "raise PHPStan to level max")
        self.assertEqual(g.fix_acro("drop php 8.3 support"), "drop PHP 8.3 support")
        self.assertEqual(g.fix_acro("add a ci check."), "add a CI check.")
        self.assertEqual(g.fix_acro("render json-ld"), "render JSON-LD")

    def test_leaves_dotted_names_and_paths_alone(self):
        self.assertEqual(g.fix_acro("run rector against rector.php"), "run Rector against rector.php")
        self.assertEqual(g.fix_acro("bump composer.json floors"), "bump composer.json floors")
        self.assertEqual(g.fix_acro("move src/rector/php files"), "move src/rector/php files")
        self.assertEqual(g.fix_acro("drop laravel-ray"), "drop laravel-ray")
        self.assertEqual(g.fix_acro("rename ci_passed"), "rename ci_passed")

    def test_leaves_code_spans_alone(self):
        self.assertEqual(g.fix_acro("apply the `php` preset in php"), "apply the `php` preset in PHP")


class TitleCleanup(unittest.TestCase):
    def test_pr_title_is_used_as_written(self):
        for title in (
            "Run PHPStan and Rector against tests and rector.php",
            "Adopt Pest's php, security, and strict arch presets and its Rector rules",
        ):
            self.assertEqual(g.clean_title(title, recase=False), title)

    def test_pr_title_still_loses_a_conventional_commit_prefix(self):
        self.assertEqual(g.clean_title("docs: correct the php preset name", recase=False),
                         "Correct the php preset name")

    def test_commit_subject_is_recased(self):
        self.assertEqual(g.clean_title("build: bump phpstan in composer.json"),
                         "Bump PHPStan in composer.json")


class Routing(unittest.TestCase):
    TITLE = "Raise dependency floors to their latest stable releases"

    def test_claude_md_counts_as_tooling(self):
        self.assertEqual(g.bucket("s", self.TITLE, paths=["CLAUDE.md", "composer.json"]), "maint")
        self.assertEqual(g.bucket("s", self.TITLE, paths=["CLAUDE.md"]), "maint")

    def test_source_changes_still_route_by_title_and_diff_shape(self):
        paths = ["CLAUDE.md", "app/Commands/ApiCommand.php"]
        self.assertEqual(g.bucket("s", self.TITLE, paths=paths, other_lines=10), "new")
        self.assertEqual(g.bucket("s", "Fix the provider name", paths=paths, other_lines=10), "fix")
        self.assertEqual(g.bucket("s", self.TITLE, paths=paths, test_lines=20, other_lines=10), "maint")

    def test_published_surface_still_wins(self):
        self.assertEqual(g.bucket("s", self.TITLE, paths=["CLAUDE.md", "config/commands.php"]), "new")

    def test_app_is_not_a_published_surface(self):
        """`app/` holds the commands AND the internals, so it must not force `new`.

        This is the rule `robot-council/core` applies to `src/`, and the reason is the same:
        a new command and a bug fix to an existing one live in the same directory. If `app/`
        were user-facing, every credential fix would be announced as a feature.
        """
        fix = ["app/Support/Credentials/WindowsCredentialStore.php"]
        self.assertEqual(g.bucket("s", "Fix a broken read", paths=fix, other_lines=10), "fix")
        self.assertEqual(g.bucket("s", self.TITLE, paths=fix, test_lines=20, other_lines=10), "maint")

    def test_this_repository_commits_its_lock_so_a_bump_is_maintenance(self):
        """`composer.lock` is committed here and is not in `robot-council/core`.

        A Dependabot bump touching only the manifest and the lock is tooling whatever its
        title says, rather than relying on the title opening with a maintenance verb.
        """
        self.assertEqual(g.bucket("s", self.TITLE, paths=["composer.json", "composer.lock"]), "maint")
        self.assertEqual(g.bucket("s", self.TITLE, paths=["box.json"]), "maint")

    def test_directories_this_repository_does_not_have_are_not_published_surfaces(self):
        """`database/`, `resources/` and `routes/` are `robot-council/core`'s, not this one's.

        Kept as a test rather than only a deletion, because a path list that names
        directories a reader will not find is how the whole skill came to describe the wrong
        repository. These route by title now, like any other unrecognised path.
        """
        for path in ("database/migrations/x.php", "resources/views/x.blade.php", "routes/web.php"):
            self.assertEqual(g.bucket("s", "Fix a broken read", paths=[path], other_lines=10), "fix",
                             msg=f"{path} should route by title, not force `new`")


class SecurityRouting(unittest.TestCase):
    """What reaches the **Security** heading, which is a claim that something was wrong.

    Both of the signals `robot-council/core` uses are unreliable here, and for the same
    underlying reason: this repository's subject IS credential handling, so "security" marks
    an area rather than a finding. Measured on `robot-council/cli#78` over all 35 merged
    subjects on `main` -- nine reached Security, every one of them wrongly.
    """

    # An area label and domain vocabulary together, and still not a security fix: it is the
    # feature that introduced the Windows store.
    AREA = "Store the credential in Windows Credential Manager, without putting it in argv"

    def test_the_security_label_marks_an_area_and_does_not_decide(self):
        """The label's own description says *security-sensitive work*, and tells reporters to
        keep exploitable vulnerabilities out of public issues entirely. So it cannot be
        evidence that a vulnerability was fixed -- one would never be on the issue.

        `Point the README at the transferred issue` routed to Security before this, because
        the issue it closed sat in a security-sensitive area.
        """
        self.assertEqual(g.bucket("s", self.AREA, labels=["security"]), "new")
        self.assertEqual(g.bucket("s", "Point the README at the transferred issue",
                                  labels=["security"], paths=["README.md"]), "maint")

    def test_domain_vocabulary_alone_is_not_a_security_claim(self):
        """`credential`, `secret`, `token` and `password` are what this command line is about.

        Each of these is a feature or a fix that happens to name one.
        """
        for title in (self.AREA,
                      "Bridge MCP over stdio, holding the credential outside the agent",
                      "Enroll a machine and keep the credential out of every transcript",
                      "Key the credential store by service and harness",
                      "Read several credentials in one call, instead of a subprocess apiece"):
            self.assertNotEqual(g.bucket("s", title), "sec", msg=title)

    def test_the_same_vocabulary_plus_an_escape_word_is_a_security_claim(self):
        """What separates the two is whether the value got OUT, not which area it sits in."""
        for title in ("Fix a credential leak in the debug log",
                      "Stop the token being exposed in the crash report",
                      "Prevent the secret from being written world-readable",
                      "Correct a credential disclosed through the error output"):
            self.assertEqual(g.bucket("s", title), "sec", msg=title)

    def test_unambiguous_terms_still_route_on_their_own(self):
        """These were never the problem -- none of them fired once across 35 subjects -- and
        they must keep working without a label, since a real advisory fix may carry none.
        """
        for title in ("Fix an SSRF in the API client",
                      "Sanitize the project label before it reaches the feed",
                      "Fix an XSS in the enrollment page"):
            self.assertEqual(g.bucket("s", title), "sec", msg=title)


class RemoteParsing(unittest.TestCase):
    """`owner/repo` out of a git remote URL, without a checkout or a network."""

    def test_reads_the_shapes_github_actually_hands_out(self):
        for url in (
            "https://github.com/robot-council/cli.git",
            "https://github.com/robot-council/cli",
            "git@github.com:robot-council/cli.git",
            "ssh://git@github.com/robot-council/cli.git",
            "https://github.com/robot-council/cli/",
        ):
            self.assertEqual(g.parse_remote(url), "robot-council/cli", url)

    def test_strips_only_a_trailing_dot_git(self):
        # A repository legitimately named with a dot must survive.
        self.assertEqual(g.parse_remote("git@github.com:o/my.repo.git"), "o/my.repo")
        self.assertEqual(g.parse_remote("git@github.com:o/my.repo"), "o/my.repo")

    def test_refuses_what_is_not_a_remote(self):
        # The point of the whole ticket: a non-answer must be a non-answer, not something
        # plausible. A path-style remote names no GitHub repository.
        for url in ("", None, "   ", "/srv/git/bare.git", "not a url"):
            self.assertIsNone(g.parse_remote(url), repr(url))


class RepoDerivation(unittest.TestCase):
    """Which repository a run is about, derived rather than defaulted."""

    @staticmethod
    def runner(results):
        """A fake command runner: maps the first two argv words to (code, stdout)."""
        def run(args):
            return results.get(" ".join(args[:2]), (1, ""))
        return run

    def test_prefers_gh_which_knows_the_configured_repository(self):
        run = self.runner({"gh repo": (0, "robot-council/cli")})
        self.assertEqual(g.derive_repo(run), "robot-council/cli")

    def test_falls_back_to_the_origin_remote_when_gh_cannot_answer(self):
        run = self.runner({
            "gh repo": (1, ""),
            "git remote": (0, "git@github.com:robot-council/cli.git"),
        })
        self.assertEqual(g.derive_repo(run), "robot-council/cli")

    def test_ignores_a_gh_answer_that_is_not_a_repository(self):
        # `gh` exiting 0 with something unusable must not be taken as an answer.
        run = self.runner({
            "gh repo": (0, "not-a-repo"),
            "git remote": (0, "https://github.com/robot-council/cli.git"),
        })
        self.assertEqual(g.derive_repo(run), "robot-council/cli")

    def test_returns_nothing_when_there_is_no_github_remote(self):
        # The case the ticket names: a checkout with no GitHub remote must produce nothing, so the
        # caller can name the flag rather than fall back to a repository that happens to exist.
        run = self.runner({"gh repo": (1, ""), "git remote": (0, "/srv/git/bare.git")})
        self.assertIsNone(g.derive_repo(run))

    def test_returns_nothing_when_there_is_no_remote_at_all(self):
        self.assertIsNone(g.derive_repo(self.runner({})))


if __name__ == "__main__":
    unittest.main()
