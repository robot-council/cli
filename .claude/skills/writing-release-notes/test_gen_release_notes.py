#!/usr/bin/env python3
"""
Offline tests for gen_release_notes.py: title cleanup and bucket routing. Neither calls
`git` or `gh`, so these run without a network or a checkout history.

  python3 -m unittest discover -s .claude/skills/writing-release-notes
"""
import contextlib
import io
import json
import os
import sys
import unittest
from unittest import mock

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

    def test_a_source_change_routes_by_title_not_by_how_many_tests_it_ships(self):
        """The correction #161 ported, and the assertion it had to invert.

        The version before it required a test-dominant `app/` change to be Maintenance. Measured
        over `v0.2.0..main`, that hid eleven user-visible changes in the v0.3.0 range, including
        both the release was named for. Rule 5 already routes genuine maintenance by path, so by
        the time the test-dominance rule is reached every change left has touched `app/`.
        """
        paths = ["CLAUDE.md", "app/Commands/ApiCommand.php"]
        neutral = "Report the fleet's roles on the dashboard"

        self.assertEqual(g.bucket("s", neutral, paths=paths, other_lines=10), "new")
        self.assertEqual(g.bucket("s", "Fix the provider name", paths=paths, other_lines=10), "fix")

        # **Still a product change when the tests outweigh it.** Taken from #149 with its real
        # line counts; before #161 this returned "maint".
        self.assertEqual(
            g.bucket("s", "Renew when this session's role changes",
                     paths=["app/Support/Bridge.php", "tests/Feature/SessionRoleChangeTest.php"],
                     test_lines=648, other_lines=160),
            "new")

        # And #159, the other change v0.3.0 is named for, at its real counts.
        self.assertEqual(
            g.bucket("s", "Let a coordinating session hear another session's role change",
                     paths=["app/Support/FleetFollower.php", "tests/Feature/FleetFollowerTest.php"],
                     test_lines=34, other_lines=18),
            "new")

        # Dependency work is Maintenance because it SAYS so, whatever its diff shape. Before #161
        # this title reached Maintenance only when its test diff happened to be the larger one.
        self.assertEqual(g.bucket("s", self.TITLE, paths=paths, other_lines=10), "maint")
        self.assertEqual(g.bucket("s", self.TITLE, paths=paths, test_lines=20, other_lines=10), "maint")

    def test_the_linked_issues_type_routes_a_change_the_verbs_miss(self):
        """#166. `FIX_VERBS` matches a title *opening* with `Fix|Resolve|Repair|...`, and almost no
        title here opens that way, because `writing-pull-requests` sanctions leading with the
        symptom or the outcome. Measured for #162 over `v0.2.0..main`: 19 bullets in What's new
        against 1 in What's fixed, roughly a dozen of the 19 being fixes.
        """
        source = ["app/Support/FleetFollower.php"]

        # #158's real title and shape: a fix whose title opens with a verb the list does not know.
        fix = "Tell a session the sweep marked it stale or gone"

        self.assertEqual(g.bucket("s", fix, paths=source, other_lines=10), "new")
        self.assertEqual(
            g.bucket("s", fix, paths=source, other_lines=10, issue_types=("Bug",)), "fix")

        self.assertEqual(
            g.bucket("s", "Report the fleet's roles on the dashboard", paths=source,
                     other_lines=10, issue_types=("Feature",)),
            "new")

    def test_the_type_is_read_after_the_maintenance_path_rule_not_before_the_verbs(self):
        """The placement is the whole of the correctness, and it is not the obvious one.

        `#154` is typed `Bug` and is confined to `.claude/`. Rule 5 routes it to Maintenance,
        which is right -- a change to a skill file is maintenance whatever the ticket it closes
        is typed. A type rule placed above rule 4 would take it first and call it a fix.
        """
        self.assertEqual(
            g.bucket("s", "Take the pull-request skill from `robot-council/core` verbatim",
                     paths=[".claude/skills/writing-pull-requests/SKILL.md"],
                     issue_types=("Bug",)),
            "maint")

        # And a category-bearing label on the linked issue still wins, because rule 2 is above
        # the type. `LABEL_SEC` is empty in this repository, so `build` is the one to assert on:
        # a `documentation` or `build` ticket is maintenance whatever its type says.
        self.assertEqual(
            g.bucket("s", "Add a release-cascade rule", labels=["build"],
                     paths=["app/Support/Thing.php"], other_lines=10,
                     issue_types=("Feature",)),
            "maint")

    def test_the_type_does_not_override_the_maintenance_rules(self):
        """#166's review measured this, and it is why the rule sits last rather than after rule 5.

        Placed after rule 5 the type also preempted rule 6 (test-dominance) and rule 7 (the
        maintenance vocabulary), so a `Bug`-typed dependency bump became a **fix** and a
        `Feature`-typed skill change became **new**. The ticket said the rule should do "nothing
        else", and that placement quietly overrode every maintenance signal a title carries.
        """
        source = ["app/Support/Store.php"]

        # Rule 7, the maintenance vocabulary: a verb, a dependency phrase, and a maintenance word.
        self.assertEqual(
            g.bucket("s", "Refactor the credential store", paths=source, other_lines=10,
                     issue_types=("Bug",)),
            "maint")
        self.assertEqual(
            g.bucket("s", self.TITLE, paths=source, other_lines=10, issue_types=("Bug",)),
            "maint")
        self.assertEqual(
            g.bucket("s", "Add a skill for cutting releases", paths=source, other_lines=10,
                     issue_types=("Feature",)),
            "maint")

        # Rule 6, a test-dominant diff with no source path.
        self.assertEqual(
            g.bucket("s", "Cover the credential store", paths=["tests/FooTest.php", "docs/x.md"],
                     test_lines=90, other_lines=10, issue_types=("Bug",)),
            "maint")

    def test_task_is_not_consulted(self):
        """`Task` predicted `fix` six times out of six on the v0.3.0 range, and that is an artifact.

        `writing-issues` assigns `Task` to a research spike, a decision fork, a follow-up cleanup
        or an epic -- never to a bug. A rule built on it breaks the first time somebody types one
        correctly, and it breaks toward the direction #162's criteria forbid.
        """
        self.assertEqual(
            g.bucket("s", "Tell a session the sweep marked it stale or gone",
                     paths=["app/Support/FleetFollower.php"], other_lines=10,
                     issue_types=("Task",)),
            "new")

    def test_an_untyped_issue_routes_exactly_as_before(self):
        """14 of the 25 in that range carried no type, so this is the majority path.

        **Asserted against the expected bucket, not against `bucket()` called twice.** The first
        version of this test compared `bucket(...)` with `bucket(..., issue_types=())`, and `()`
        is the parameter's default -- so the two calls were byte-identical and it asserted
        `f(x) == f(x)`, which holds for every implementation including one that routed everything
        to Maintenance.
        """
        source = ["app/Support/FleetFollower.php"]
        rules = [".claude/rules/worktrees.md"]

        # (title, paths, the bucket this reached before the type rule existed)
        for title, paths, expected in (
            ("Tell a session the sweep marked it stale or gone", source, "new"),
            ("Fix the provider name", source, "fix"),
            (self.TITLE, source, "maint"),
            ("Tell a session the sweep marked it stale or gone", rules, "maint"),
            ("Fix the provider name", rules, "fix"),
        ):
            self.assertEqual(
                g.bucket("s", title, paths=paths, other_lines=10, issue_types=()),
                expected,
                f"{title} / {paths}")

    def test_a_feature_and_a_bug_together_route_to_whats_new(self):
        """A pull request can close several issues of differing types, and the buckets fail
        asymmetrically: a fix under What's new is visible and merely mislabelled, while a feature
        under What's fixed understates the release. Only the second is forbidden, so the mixed
        case takes the safe direction.
        """
        source = ["app/Support/FleetFollower.php"]
        title = "Tell a session the sweep marked it stale or gone"

        self.assertEqual(
            g.bucket("s", title, paths=source, other_lines=10, issue_types=("Bug", "Feature")),
            "new")
        self.assertEqual(
            g.bucket("s", title, paths=source, other_lines=10, issue_types=("Feature", "Bug")),
            "new")

    def test_a_feature_titled_like_a_fix_does_not_reach_whats_fixed(self):
        """#121, named in #162's criteria as the shape most likely to be caught by a wrong rule:
        a **feature** whose title opens with `Say`. A widened `FIX_VERBS` was rejected for exactly
        this, and the type rule must not reintroduce it.
        """
        title = "Say when nothing on the fleet can reach a waiting agent"
        source = ["app/Support/FleetDelivery.php"]

        self.assertEqual(g.bucket("s", title, paths=source, other_lines=10), "new")
        self.assertEqual(
            g.bucket("s", title, paths=source, other_lines=10, issue_types=("Feature",)), "new")

        # Typed `Bug`, a human said it was a fix, and the rule does not argue with them.
        self.assertEqual(
            g.bucket("s", title, paths=source, other_lines=10, issue_types=("Bug",)), "fix")

    def test_the_type_is_case_insensitive(self):
        """GitHub returns `Bug` and `Feature`; nothing should depend on that casing."""
        source = ["app/Support/FleetFollower.php"]
        title = "Tell a session the sweep marked it stale or gone"

        for spelling in ("Bug", "bug", "BUG"):
            self.assertEqual(
                g.bucket("s", title, paths=source, other_lines=10, issue_types=(spelling,)),
                "fix", spelling)

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

        # **Maintenance by its title, not by its diff shape.** This assertion used to pass through
        # the test-dominance rule, which #161 stopped firing on `app/`; it now passes because the
        # title is dependency work. Left deliberately, because a test that goes on passing for a
        # different reason than it was written for is worth nailing down rather than deleting.
        self.assertEqual(g.bucket("s", self.TITLE, paths=fix, test_lines=20, other_lines=10), "maint")

        # And the case that distinguishes them: same paths, same shape, a title claiming nothing.
        # Before #161 this was "maint", which is how #109 and #110 -- ordinary credential-store
        # fixes -- were filed as tooling.
        self.assertEqual(
            g.bucket("s", "Read the legacy credential once per refusal, not twice",
                     paths=fix + ["tests/Feature/KeychainStoreTest.php"],
                     test_lines=108, other_lines=32),
            "new")

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


class PullRequestCache(unittest.TestCase):
    """The plumbing between the GraphQL query and `bucket()`.

    **Every line of it was a silent no-op under mutation and all 31 tests stayed green** (#166's
    review): deleting `issueType{name}` from the query, hard-coding the parsed types to `()`, and
    reading the wrong cache slot each left the feature inert. The range run caught it, but a range
    run is evidence, not a regression test.
    """

    def setUp(self):
        g._pr_cache.clear()
        self.addCleanup(g._pr_cache.clear)

    @staticmethod
    def _answers(payload):
        """Stand in for `gh api graphql`, returning one canned response."""
        class Result:
            returncode = 0
            stdout = json.dumps(payload)
            stderr = ""

        return lambda *a, **k: Result()

    def _prime(self, nodes, number=7):
        payload = {"data": {"repository": {
            f"p{number}": {"title": "A title", "closingIssuesReferences": {"nodes": nodes}}}}}
        with mock.patch.object(g.subprocess, "run", self._answers(payload)):
            g.prime_pr_cache([number], "owner/name")

    def test_the_type_reaches_the_accessor_and_is_not_the_labels(self):
        """The label and the type differ on purpose.

        Reading the wrong slot would answer `("documentation",)`, which is exactly the mutation
        that survived -- and it would route any issue labelled `bug` into What's fixed with
        nothing to report it.
        """
        self._prime([{"issueType": {"name": "Bug"},
                      "labels": {"nodes": [{"name": "documentation"}]}}])

        self.assertEqual(g.pr_issue_types(7), ("bug",))
        self.assertEqual(g.pr_labels(7), ("documentation",))
        self.assertEqual(g.pr_title(7, "owner/name"), "A title")

    def test_the_query_asks_for_the_type(self):
        """Deleting the field from the query is invisible to every other test here."""
        sent = []

        class Result:
            returncode = 0
            stdout = json.dumps({"data": {"repository": {"p11": None}}})
            stderr = ""

        def record(args, **kwargs):
            sent.append(args)
            return Result()

        with mock.patch.object(g.subprocess, "run", record):
            g.prime_pr_cache([11], "owner/name")

        query = " ".join(" ".join(a) for a in sent)

        self.assertIn("issueType", query)
        self.assertIn("closingIssuesReferences", query)

    def test_an_issue_with_no_type_yields_no_type(self):
        """`issueType` is null on the majority of issues, and a null node must not become a name."""
        self._prime([{"issueType": None, "labels": {"nodes": []}}])

        self.assertEqual(g.pr_issue_types(7), ())

    def test_several_closed_issues_contribute_several_types(self):
        self._prime([
            {"issueType": {"name": "Bug"}, "labels": {"nodes": []}},
            {"issueType": {"name": "Feature"}, "labels": {"nodes": []}},
        ])

        self.assertEqual(set(g.pr_issue_types(7)), {"bug", "feature"})

    def test_a_number_that_is_not_a_pull_request_answers_empty(self):
        """`prime_pr_cache` caches `(None, (), ())` for an alias that resolved to nothing, and the
        three-slot shape has to hold there too or the accessors raise.

        The alias comes back `null` rather than missing, which is how GitHub answers a number that
        is not a pull request. An empty `repository` would be a batch that returned nothing at all,
        and `NoDataGuard` below says that warns.
        """
        with mock.patch.object(g.subprocess, "run",
                               self._answers({"data": {"repository": {"p99": None}}})):
            g.prime_pr_cache([99], "owner/name")

        self.assertEqual(g.pr_issue_types(99), ())
        self.assertEqual(g.pr_labels(99), ())
        self.assertIsNone(g.pr_title(99, "owner/name"))


class NoDataGuard(unittest.TestCase):
    """The two warnings `robot-council/core#274`'s review added, and #178 did not port.

    Both are about a query that returned less than it looks like: a batch with no data at all,
    and a closing-issue list longer than the page that read it.
    """

    def setUp(self):
        g._pr_cache.clear()
        self.addCleanup(g._pr_cache.clear)

    @staticmethod
    def _drive(stdout, returncode=0):
        """Run one batch for #7 against a canned `gh` answer, and return what reached stderr."""
        class Result:
            pass

        Result.stdout, Result.stderr, Result.returncode = stdout, "", returncode
        err = io.StringIO()
        with mock.patch.object(g.subprocess, "run", lambda *a, **k: Result()), \
                contextlib.redirect_stderr(err):
            g.prime_pr_cache([7], "owner/name")

        return err.getvalue()

    @staticmethod
    def _ok(total, nodes):
        return json.dumps({"data": {"repository": {"p7": {
            "title": "T", "closingIssuesReferences": {"totalCount": total, "nodes": nodes}}}}})

    def test_every_no_data_shape_warns_not_only_the_ones_with_an_errors_array(self):
        """The last three are the shapes a guard gated on `errors` stays silent for.

        Each leaves the whole batch uncached, so every bullet in it loses its link -- the outcome
        the partial-data handling exists to prevent.
        """
        shapes = {
            "a validation error": json.dumps({"data": None, "errors": [
                {"type": "INVALID", "message": "Field 'issueType' doesn't exist on type 'Issue'"}]}),
            "an empty errors array": json.dumps({"data": None, "errors": []}),
            "repository null": json.dumps({"data": {"repository": None}}),
            "empty stdout": "",
            "an HTML error page": "<html>gateway timeout</html>",
        }
        for label, body in shapes.items():
            with self.subTest(label):
                out = self._drive(body, returncode=0 if body.startswith("{") else 1)

                self.assertIn("returned no data for #7-#7", out)
                self.assertIn("carry no links", out)
                self.assertIsNone(g.pr_title(7, "owner/name"))
            g._pr_cache.clear()

    def test_the_warning_names_the_cause_it_was_given(self):
        """With an `errors` array the cause is GitHub's; without one it is the exit code."""
        self.assertIn("INVALID: Field 'issueType'", self._drive(json.dumps({"data": None, "errors": [
            {"type": "INVALID", "message": "Field 'issueType' doesn't exist on type 'Issue'"}]})))
        g._pr_cache.clear()
        self.assertIn("gh exit 1, 0 bytes of stdout", self._drive("", returncode=1))

    def test_a_healthy_response_warns_about_nothing(self):
        """The negative control. A guard that cries wolf is turned off within a week."""
        shapes = {
            "one typed issue": self._ok(1, [{"issueType": {"name": "Bug"},
                                             "labels": {"nodes": [{"name": "development"}]}}]),
            "one untyped issue": self._ok(1, [{"issueType": None, "labels": {"nodes": []}}]),
            "no closing issues": self._ok(0, []),
            "nodes null": self._ok(0, None),
            "a number that is not a pull request": json.dumps({"data": {"repository": {"p7": None}}}),
        }
        for label, body in shapes.items():
            with self.subTest(label):
                self.assertEqual(self._drive(body), "")
            g._pr_cache.clear()

    def test_truncation_is_reported_rather_than_guessed(self):
        """Since #178 a dropped closing issue can decide the BUCKET, not just lose a label."""
        out = self._drive(self._ok(25, [{"issueType": {"name": "Feature"}, "labels": {"nodes": []}}]))

        self.assertIn("#7 closes 25 issues; only 1 were read", out)
        self.assertEqual(g.pr_issue_types(7), ("feature",))

    def test_a_full_page_that_is_the_whole_list_is_not_truncation(self):
        """The boundary: `totalCount` equal to what was read is complete, and says nothing."""
        nodes = [{"issueType": None, "labels": {"nodes": []}}] * 20

        self.assertEqual(self._drive(self._ok(20, nodes)), "")

    def test_the_query_reads_the_total_and_a_page_of_twenty(self):
        """Without `totalCount` in the query, the truncation check compares against nothing."""
        sent = []

        class Result:
            returncode = 0
            stdout = json.dumps({"data": {"repository": {"p11": None}}})
            stderr = ""

        def record(args, **kwargs):
            sent.append(" ".join(args))
            return Result()

        with mock.patch.object(g.subprocess, "run", record):
            g.prime_pr_cache([11], "owner/name")

        self.assertIn("closingIssuesReferences(first:20){totalCount ", sent[0])


if __name__ == "__main__":
    unittest.main()
