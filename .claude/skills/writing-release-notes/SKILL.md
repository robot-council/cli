---
name: writing-release-notes
description: >-
  GitHub Release conventions for the `robot-council/cli` command line: the
  em-dash release title (`vX.Y.Z — Theme`), a one-sentence milestone lead with an optional
  `**Breaking change**` callout, and a CLOSED, ordered heading vocabulary
  (`## Breaking changes`, `## What's new`, `## What's fixed`, `## Security`,
  `## Maintenance and tooling`) with one bullet per change formatted as
  `- <PR title> [#N](…/pull/N)` — using the API PR title (never a merge or squash commit
  subject), no `by @author`, inline code preserved. Covers the routing cascade and the bundled
  generator, what a tag of this repository promises and to whom (a release marker rather than a
  Composer constraint, kept to semantic versioning anyway), cutting a release (the `CHANGELOG.md` pull
  request, then the tag and the GitHub Release), and the retroactive-tag footer. Activate whenever drafting,
  rewriting, or critiquing a GitHub Release title or body, generating release notes, or cutting
  a tag for this repo.
---

# Writing Release Notes

House style for GitHub Releases in `robot-council/cli`. It applies to the **whole release
range** — retroactive tags and new ones alike — so the releases page and `CHANGELOG.md` read as one
consistent changelog. The audience is a developer deciding whether to take this version of the
command; lead with the theme, then bucket the changes.

**This file was a byte-identical copy of `robot-council/core`'s** until `robot-council/cli#69`, and
described that package throughout. What is still shared with it is shared because it was decided to
be — the title shape, the closed heading vocabulary, the bullet and link format, the prose rules,
and the cascade's *structure* are deliberately the same, so a reader moving between the two
repositories is reading one convention. What differs is marked where it differs: the versioning
section below, the bucket definitions, the paths the cascade routes on, and **what reaches the
Security heading**, which `robot-council/cli#78` changed for a reason that does not apply there.

## Versioning — what the number promises

**These tags are resolved as a Composer constraint.** Published to Packagist on 2026-09-22,
decided on `robot-council/cli#75`, with `composer.json` declaring `"type": "library"` to match
`statamic/cli` and `laravel/installer`. The install the README documents and has verified is:

```bash
composer global require robot-council/cli
```

Measured the day it was published, into a throwaway `COMPOSER_HOME`: that line prints
`Using version ^0.2.0 for robot-council/cli` and installs the tag. So a tag now has a **resolver**
as well as a reader, and what it promises is no longer a courtesy. This section said the opposite
until `#75`; the history is in `robot-council/cli#69`, which decided the question while nothing
resolved a caret.

**Tag semantically, and keep the 0.x rule.** Use `MAJOR.MINOR.PATCH`, and below 1.0 let a breaking
change bump the **minor**, because that is how Composer's caret reads it: `^0.3` means
`>=0.3.0 <0.4.0`, while `^1.2` means `>=1.2.0 <2.0.0`. From `1.0.0` on, a breaking change bumps the
**major**. When to cut a release, and when `1.0.0` happens, are not decided here.

**A GitHub pre-release flag does not make Composer treat a version as unstable**, and reaching for
it when you meant a resolver to skip a version is the trap. The flag is GitHub metadata: it drives
the `Latest` badge and the release page, and Packagist never sees it. Composer reads stability from
the **version string** alone. Measured on `v0.2.0`, which is flagged `prerelease=true` on GitHub and
which Packagist serves as `version_normalized=0.2.0.0` — a stable version, installed by a default
`composer global require` with no warning and no `minimum-stability` change.

So the two mechanisms have different audiences, and both are legitimate:

| to tell | use |
| --- | --- |
| a person reading the releases page that a version is provisional | the GitHub `prerelease` flag |
| a **resolver** to skip a version | a tag suffix — `v0.3.0-beta.1`, normalizing to `0.3.0.0-beta1` |

A suffixed tag is resolved only by a caller whose `minimum-stability` admits it. `robot-council/cli#101`
and `robot-council/core#154` record this, including why the releases already flagged this way are
better accepted than re-tagged: Packagist has served them, and a consumer may have resolved them.

**Breaking, for a command, is a change to what somebody types or parses**: a command or flag removed
or renamed, output another program reads changed, a config key moved, or an enrolled machine having
to re-enroll. It is not a changed class name — nothing requires this package and calls into it.

## Title

`vX.Y.Z — <Theme>` — an **em dash** (`—`) with a space either side, never a hyphen, then a
concise Title-Case theme (no trailing period). The one release this repository has cut is the
example to follow: `v0.1.0 — Enrollment, Credential Storage, and the MCP Bridge`.

The title is not cosmetic: it is also the version heading of the release's `CHANGELOG.md` entry
(see *Cutting a release* below).

## Body structure (in order)

1. **Milestone lead** — one sentence naming the release's theme.
2. **Breaking-change callout** (only when the release breaks something) — its **own paragraph**
   immediately after the lead, never appended to the lede sentence:
   `**Breaking change** — <impact and required action>.` State what a developer must *do* on
   upgrade (re-enroll the machine, rename a flag in a harness's MCP configuration, move a config
   key, adjust whatever parses the bridge's output), not the semver mechanics. The itemized detail
   with PR links goes in the `## Breaking changes` section below.
3. **Buckets** — a **CLOSED** set of `##` headings, each included **only when it has items**,
   always in this order:
   - `## Breaking changes` — anything a developer must act on to upgrade: a command or flag
     removed or renamed; a change to output another program parses, such as the MCP bridge's
     stdio protocol; a moved or removed config key; a credential-store change that makes an
     enrolled machine re-enroll. Name the impact, not just the change. A changed class or method
     signature is **not** breaking here — nothing requires this package and calls into it.
   - `## What's new` — new commands, flags and options, config keys, harness integrations, and
     anything a developer can now do that they could not before.
   - `## What's fixed` — bug fixes, regressions, correctness and performance fixes.
   - `## Security` — vulnerability fixes and hardening. Here that is mostly the credential: keeping
     it out of argv, out of stdout and a log, out of an exception trace, and out of a file anything
     else can read.

     **Neither of the two signals `robot-council/core` routes on works here, and `robot-council/cli#78`
     changed both.** Measured over all 35 merged subjects on `main`, with the labels their closing
     issues actually carry: **nine reached this heading and none belonged.** They included the bridge,
     enrollment, the Windows credential store, and `Point the README at the transferred issue`.

     - **The `security` label marks an area, not a finding**, and no longer decides. Its own
       description is *"Security-sensitive work; report exploitable vulnerabilities privately, not in
       a public issue"* — so an exploitable vulnerability is never on a public issue to carry it.
       That goes to a draft advisory, per `CLAUDE.md`.
     - **`secret`, `credential`, `token` and `password` no longer route on their own.** In `core` they
       are unusual enough to be a signal; here they are the product. They now need a second word
       saying the value **escaped** — `leak`, `exposed`, `disclosed`, `world-readable`, `in the clear`.
       The unambiguous web terms (SSRF, XSS, sanitize) still route alone, and never once misfired.

     Write the title so it says what got out, and it will route itself. **Nothing routes a change here
     automatically any more**, so if a release fixes something exploitable, put it under this heading
     deliberately and check the generator agreed.
   - `## Maintenance and tooling` — docs, CI, tests, refactors, dependency bumps, chores, and
     developer-experience / skills work.
4. **Footer** — retroactively-tagged releases only: `_Retroactively tagged at \`<sha>\` (<date>)._`
   Real-time releases omit it.

Do **not** invent headings outside this closed set. If something doesn't obviously fit, it is
*New*, *Fixed*, or *Maintenance and tooling* — decide by dominant intent.

## Line format

- One bullet per change: `- <PR title> [#N](https://github.com/robot-council/cli/pull/N)`.
- **Use the PR title from the GitHub API** (`gh pr view N --json title`), **never the commit
  subject on `main`.** A merge commit's subject is a branch slug (`Merge pull request #N from
  robot-council/<branch>`), and this repo's squash setting (`COMMIT_OR_PR_TITLE`) takes the
  *commit's* title when a PR has a single commit, so neither is reliably the PR title. For a change
  on `main` with no PR number in its subject — a direct commit, or each commit of a rebase merge —
  use the commit subject (strip any Conventional-Commit prefix and `[skip ci]` litter) and link the
  **short commit SHA**:
  `` - <title> [`a1b2c3d`](https://github.com/robot-council/cli/commit/<sha>) ``.
  Every bullet is linked — `[#N]` for a PR, a backticked short SHA for a direct commit.
- **No `by @author`.** On a single-maintainer repository attribution is noise. GitHub's
  auto-generated notes add it, which is one reason not to use them.
- Preserve inline code in titles (class names, config keys, paths, package names).
- Don't restate the lead inside a bucket; don't add an `H1`.

## Prose style (titles, leads, and bullets)

- **Never use an ampersand (`&`)** — write "and". This applies to release titles, the lead, and
  every bullet.
- **Use the Oxford comma** — `enrollment, credential storage, and the MCP bridge`, not
  `enrollment, credential storage and the MCP bridge`.

## Routing (which bucket) — by title, closing-issue label, and diff shape

A change routes on its resolved title, the **labels** and the **GitHub issue type** of the issue
its PR closes (read from that issue, not from the PR), and which paths its diff touches. A cascade,
first match wins — the order is what makes it correct:

1. **Breaking changes** — editorial call, per the bucket definition above; the generator cannot
   infer it, so it is passed in by flag.
2. **Security** — a `security` label, or a title mentioning `XSS`, `SSRF`, `CSP`, `HSTS`, `XXE`,
   `ReDoS`, egress, nonce, impersonation, sanitize, SSL verification, security header,
   `X-Powered-By`, password protection, internal-network, or "escape" of a script/HTML/JSON-LD sink.
3. **Maintenance and tooling** — a `documentation` (or `build`) label on the closing issue. Checked
   before the fix verbs, because a maintenance title can open with `Correct` or `Stop`.
4. **What's new** — the diff touches `config/`, whatever its title says: `config/commands.php`
   decides which commands ship, which is the nearest thing this repository has to a published
   surface. **`app/` is deliberately not on that list**, for the reason `robot-council/core` leaves
   `src/` off its own: it holds the internals as well as the commands, and a new command and a bug
   fix to an existing one live in the same directory — often the same file. A change there routes
   on its title and on how test-heavy the diff is. `database/`, `resources/` and `routes/` are not
   on the list either, because this repository has none of them.
5. **What's fixed** — the title opens with `Fix`/`Resolve`/`Repair`/`Prevent`/`Guard`/`Restore`/
   `Correct`/`Harden`/`Stop`/`Avoid`.
6. **Maintenance and tooling** — the diff is confined to tooling (`.github/`, `.claude/`, `tests/`,
   `composer.json`, `composer.lock`, `box.json`, `phpstan.neon.dist`, `phpunit.xml.dist`, `rector.php`,
   top-level dotfiles, `CHANGELOG.md`, `CLAUDE.md`, `README.md`, `LICENSE.md`).

7. **Maintenance and tooling** — the diff adds more lines under `tests/` than elsewhere **and
   touches no source path**. The source exclusion is what makes this mean anything: by the time
   control arrives here every remaining change has touched `app/`, and comparing line counts alone
   would decide a product change on the size of its test suite. This repository asks for a control
   per detector and a negative control per fix, so a test-dominant diff is the normal shape of a
   feature here. Measured over `v0.2.0..main` before the exclusion existed, eleven user-visible
   changes landed in Maintenance this way, including both the v0.3.0 release was named for (#161,
   ported from `robot-council/core#266`).

8. **Maintenance and tooling** — the title opens with a maintenance verb (`Refactor`, `Bump`,
   `Document`, …) or names tests, coverage, mutation, a skill, or a worktree.

9. **What's fixed / What's new** — the closing issue's **GitHub issue type**: `Bug` routes to
   *What's fixed*, `Feature` to *What's new* (#166). **Last, so it decides only what nothing else
   could.** Step 5 matches a title *opening* with a fix verb, and almost no title here opens that
   way, because `writing-pull-requests` sanctions leading with the symptom or the outcome instead —
   so fixes were landing in *What's new*.

   It sits below every maintenance rule deliberately. Placed higher it preempted them, and a
   `Bug`-typed dependency bump became a fix while a `Feature`-typed skill change became new. Here
   it cannot: anything the paths, the labels or the title already identify as maintenance has
   returned before control reaches it.

   **`Task` is not consulted**, although it predicted `fix` six times out of six on the v0.3.0
   range. `writing-issues` assigns it to a spike, a decision fork, a cleanup or an epic, never to a
   bug, so that agreement is an artifact of how those tickets happened to be typed. An issue with
   no type set — 14 of the 25 in that range — reaches none of this.

   `composer.lock` is on that list because **this repository commits it and `robot-council/core`
   does not**, so a Dependabot bump lands as a manifest-and-lock diff here. `box.json` is the PHAR
   build config. There is no `phpstan-baseline.neon` and deliberately so — `CLAUDE.md` says to fix
   the errors instead — and no `workbench/`, which is a package's host application.
10. **What's new** — everything else.

## Generating the body — [`gen_release_notes.py`](gen_release_notes.py)

Don't hand-assemble the buckets — run the bundled generator. It reads first-parent git history
for a ref range, pulls each PR's title **live from the GitHub API** (`gh`), and applies every
rule above: prefix/`[skip ci]`/merge-hint stripping, acronym casing of a direct commit's subject
(never of a PR title, which is used as written, and never of a dotted name, a path, or a code
span), the routing cascade, `&` rewritten to `and` with the Oxford comma, `[#N]` PR links, and
backticked-short-SHA links for direct commits. It skips changelog pull requests titled
`Update CHANGELOG for vX.Y.Z`. It depends only on `git`, `gh`, and Python 3 (3.9 or later) — no
other setup. Its title cleanup and routing are tested offline by
[`test_gen_release_notes.py`](test_gen_release_notes.py):
`python3 -m unittest discover -s .claude/skills/writing-release-notes`.

The **editorial** parts it can't infer are passed as flags: the one-sentence `--lead`, the
`--breaking` callout impact, and any `--breaking-item` bullets (`--exclude` a PR itemized there
so it doesn't also auto-list in a bucket).

```
python3 .claude/skills/writing-release-notes/gen_release_notes.py <prev-tag> origin/main \
    --lead "One-sentence milestone theme." \
    --breaking "republish the config file and rename \`seats\` to \`members\`." \
    --breaking-item "Rename the \`seats\` config key to \`members\` [#12](https://github.com/robot-council/cli/pull/12)." \
    --exclude 12 \
    > body.md
```

The new tag does not exist yet when the body is generated, so the range ends at `origin/main`
(fetch first). Then review `body.md` and use it as below. `--help` lists all flags; `<prev-tag>`
may be `-` for the repo root (first release). The routing is a heuristic: read every
bucket before publishing and move a bullet the cascade misfiled.

## Cutting a release

**`CHANGELOG.md` changes through a pull request like everything else, before the tag.** Nothing
writes it automatically: the `main` ruleset requires a pull request and a successful `ci-passed` for
every change, so the tag must point at a commit that already carries the entry.

1. **Generate and review the body** against `origin/main`, as above.

2. **Open the changelog pull request** from a branch such as `changelog-vX.Y.Z`, titled exactly
   `Update CHANGELOG for vX.Y.Z` (the generator skips that title, so the pull request never lists
   itself in a later release). Add the entry at the top of `CHANGELOG.md`, directly under the intro
   paragraph: a `## vX.Y.Z — <Theme> (YYYY-MM-DD)` heading, then the body with each `##` heading
   demoted to `###` so the buckets nest under the version:

   ```
   sed 's/^## /### /' body.md
   ```

   Merge it once `ci-passed` succeeds and [`pre-merge-check`](../../rules/pre-merge-check.md) is
   done.

3. **Tag the merge and publish the release**, after confirming that `origin/main` is the changelog
   merge and nothing else landed after it:

   ```
   git fetch origin
   git log --oneline -1 origin/main
   git tag -a vX.Y.Z origin/main -m 'vX.Y.Z — <Theme>'
   git push origin vX.Y.Z
   gh release create vX.Y.Z --title 'vX.Y.Z — <Theme>' --notes-file body.md --verify-tag
   ```

   Write the body to a file and pass `--notes-file` (never inline `--notes` — the bodies are dense
   with backticks, `#`, and `—` that the shell mangles). The release body is `body.md` as generated,
   with `##` headings; only the `CHANGELOG.md` copy is demoted.

**Keep the release and the entry in step.** `gh release edit vX.Y.Z --title '…' --notes-file notes.md`
changes only the release, so a correction to one is a pull request to the other.

Retroactive tags: create an **annotated** tag stamped with the target commit's date so
`git tag --sort=creatordate` orders correctly —
`GIT_COMMITTER_DATE="$(git log -1 --format=%cI <sha>)" git tag -a vX.Y.Z <sha> -m '…'`. GitHub's
`published_at` is still the publish time (not backdatable via any API); the API's `created_at`
already reflects the tagged commit's date.

## The DRY line

This is the standing statement of Release conventions. It composes with
[`writing-commits`](../writing-commits/SKILL.md) and
[`writing-pull-requests`](../writing-pull-requests/SKILL.md) (the PR titles this skill renders
come from those) and with [`pre-merge-check`](../../rules/pre-merge-check.md), which the changelog
pull request goes through like any other. The `gh` flags live in that tool; don't restate them.
