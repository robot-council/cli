---
name: writing-pull-requests
description: >-
  Pull-request title and body conventions for this repository. The title is
  imperative verb-first, has no Conventional-Commit prefix and no internal-process references
  (batch or merge-order hints), with correct acronym casing and inline-code handles — it renders
  verbatim into GitHub Release notes. The body: a one-paragraph lede opening with `Closes #N.`,
  optional `## Approach` / `## Commits` / `## Files` / `## Verification` / `## The bug` /
  `## Out of scope` / `## Follow-up` / `## Companion PRs` sections, a mandatory `## Test plan`
  GitHub task-list naming the real checks, `##`/`###` headings, `-` bullets with 2-space nesting
  and em-dash separators, aggressive inline-code markup, linked file paths whose target is the
  absolute branch URL, and a canonical `Closes #N.` reference — with cross-repo references
  rendered as a backticked owner/repo#N link. Covers closing-keyword traps (negation, code spans,
  cross-line pairs) and how they map onto squash, merge, and rebase merges, plus the PR lifecycle:
  draft until ready for review, the `CI` workflow, and its
  required `ci-passed` check. Activate whenever drafting, rewriting,
  opening, or critiquing a GitHub pull request for this repository.
---

# Writing Pull Requests

This skill captures the house style for PR descriptions. The audience is another engineer
reviewing the change — explain the *why* before the *what*, prefer specifics over generalities,
and bound scope explicitly. There is no PR template in `.github/`; this skill is the skeleton.

**It is one shared document, carried identically by `robot-council/core` and `robot-council/cli`,
so it names no repository of its own.** The `gh` recipes take `{owner}` and `{repo}` from the
checkout, link templates are written with `<owner>`/`<repo>` placeholders, and the worked example
says which repository its files come from. A recipe that named a fixed repository would act on
*that* one from either tree, and the assignee recipe below is a **write** — so parameterizing them
is a correctness property, not tidiness. Where the two repositories genuinely differ, such as the
CI jobs, this file states the contract and sends you to the tree you are in.

Some illustrative identifiers elsewhere are still the package's — its dependency handles under
`## Inline code markup`, for instance. They are examples of what to backtick rather than paths to
open, and they are left as they are.

## Output format

When proposing a PR body, **present the final body as a single fenced Markdown code block** so
the user can copy it cleanly. If the body itself contains fenced blocks, open the outer fence with
more backticks than any inner one. The body uses GitHub-Flavored Markdown — never include an `H1`
(`#`) heading; GitHub renders the PR title separately.

When you create the PR yourself with `gh`, **write the body to a file and pass `--body-file`
from the start** — never inline the Markdown via `--body`. These bodies are dense with
backticks, `$`, `!`, backslashes, and fenced code blocks, every one of which the shell mangles
or errors on as an inline argument (the failure is intermittent, so inline `--body` looks fine
until a body finally trips it). Write it to a scratch file and
`gh pr create --draft --base main --head <branch> --title '…' --body-file pr-body.md`; the same
holds for `gh pr edit <n> --body-file`.

## Draft state and CI

- **Open as draft until the branch is ready for review; flip it with `gh pr ready <n>` when it
  is.** Never present a PR as ready while its branch still needs work.
- **Every pull request runs the same checks.** `.github/workflows/ci.yml` runs on every pull
  request, with no path filters:
  - `tests` — `vendor/bin/pest --ci` across a matrix of operating systems and PHP versions, with
    `fail-fast: false`, so every cell reports. **The cells differ between the two repositories** —
    the package resolves dependencies fresh at `prefer-lowest` and `prefer-stable` because it
    commits no lockfile, while the application installs from a committed one — so read
    `.github/workflows/ci.yml` in the tree you are in rather than assuming a shape.
  - `phpstan` — PHPStan, run through `composer analyse`.
  - `pint` — `vendor/bin/pint --test`, which fails on a style problem and fixes nothing. Run
    `vendor/bin/pint --dirty` before pushing.
  - `rector` — `vendor/bin/rector --dry-run`, which fails when Rector would change a file. Run
    `composer refactor` before pushing, and review its changes.
  - `ci-passed` — the single check the `main` ruleset requires, alongside a pull request and a
    branch that is up to date with `main`. **It gates every other job in that tree's `ci.yml`, and
    the two repositories do not have the same set.** Measured: the application's `needs` is
    `[tests, phpstan, pint, rector]`, the package's is
    `[tests, phpstan, pint, rector, postgres, mysql, stylesheet]`. So the four above are the common
    subset, not the gate — read `needs:` in the tree you are in before saying what a green check
    covers.
- **A PR's checks describe its current head only.** After a push, or after syncing with `main`
  per [`sync-pr-branch`](../../rules/sync-pr-branch.md), read `ci-passed` again on the new head
  rather than trusting an earlier green.

## Assign yourself to every pull request you open

**Every PR carries an assignee, and it is the developer responsible for landing it.** Not a reviewer, and not a nicety — it is the only place a reader can see who owns a branch that is open and quiet.

```bash
gh api -X POST 'repos/{owner}/{repo}/issues/<pr-number>' -f 'assignees[]=<login>'
```

**`{owner}` and `{repo}` are literal, and `gh` fills them from the checkout you run in.** Written that way the recipe follows the tree rather than a name baked into it, which matters most here because this one is a **write**: a fixed repository name followed from the wrong tree assigns somebody to *that* repository's issue carrying the same number, and the call returns an ordinary success because the number exists in both trackers. Nothing in the result says which repository was touched.

**The bound worth knowing:** `gh` resolves the placeholders from its notion of the *current* repository, which `GH_REPO` and `gh repo set-default` both move — measured, `GH_REPO=robot-council/core` redirects them from inside this checkout. So the recipe follows the checkout; it is not a guarantee that no other repository can be reached. Read the response's `repository_url` when it matters.

**Keep the quotes, and not for the reason that looks obvious.** PowerShell parses an unquoted `{…}` as a script block and splits the argument into five, and an unquoted `<N>` is a redirection in `bash`. Both measured on Windows, where this repository's agents use either shell. **`bash` does not brace-expand `{owner}`** — that needs a comma or a range — so an agent reasoning only from the `bash` tag on the fence concludes the quotes are ornamental and drops them, and the recipe then breaks for whoever runs it in the other shell.

The PR number works on the `issues` endpoint — GitHub treats pull requests as issues for labels, assignees and comments. **That endpoint replaces the assignee list; the sibling `…/issues/{n}/assignees` adds to it.** The two diverge only when a request omits somebody already assigned, so a hand-over written against the wrong one silently produces two assignees — the mechanics are in [`github-api-budget`](../../rules/github-api-budget.md).

## Title

The PR title is **public changelog copy** — it is rendered verbatim into the GitHub Release
notes (see [`writing-release-notes`](../writing-release-notes/SKILL.md)), and from the release
body into `CHANGELOG.md`, so write it for a reader skimming "what shipped," not for the branch.

- **Imperative, verb-first**, capitalized first word, **no trailing period** —
  `Add a members list to the published config`, not `members config` or `Added members config.`.
  A bug fix may lead with the symptom or the fix (`Fix …`), consistent with
  [`writing-issues`](../writing-issues/SKILL.md).
- **No Conventional-Commit prefix.** `feat:`, `fix:`, `perf:`, `refactor:`, `chore:`, `docs:`,
  `build:`, `ci:`, `test:`, `style:` — and scoped forms like `feat(config):` — belong on
  **commits** ([`writing-commits`](../writing-commits/SKILL.md)), never on the PR title; the
  release-note section heading already conveys the change type.
- **No internal-process references.** Strip batch labels, merge-order hints (`(merge after #N)`),
  redundant parenthetical issue refs (`(#N, #M)`), and bookkeeping like "follow-up to PR #N" —
  state the actual change.
- **Correct casing** for acronyms and proper nouns: PHP, CI, CLI, API, JSON, PCOV, XSS, SSRF,
  CSP, Composer, Laravel, Pest, PHPUnit, PHPStan, Larastan, Pint, Testbench, GitHub Actions,
  macOS.
- **Inline code** for class names, config keys, paths, commands, and packages
  (`RobotCouncilServiceProvider`, `robot-council.members`, `spatie/laravel-package-tools`).
- **Never use an ampersand (`&`)** — write "and". **Use the Oxford comma.**
- Concise but clear — expand cryptic shorthand where the meaning isn't obvious.

## Opening (lede)

Every PR opens with a bare `Closes #N.` line immediately followed by a 1–3-sentence prose
paragraph — no `## Summary` heading. On larger PRs the paragraph may give way to a short
bullet list, but the close reference still leads.

The lede should answer "what changed, and why now?" in the space of a paragraph. Lead with
the user-visible effect or root cause, not the implementation.

## Sections

Use `##` for top-level sections, `###` for sub-sections inside them. The conventional section
inventory, in order of appearance:

- **`## The bug`** — fix PRs only; explains the root cause before the fix.
- **`## Approach`** — used when a resolution path was chosen over alternatives, or when
  reviewers need to see the design rationale. Link the issue-comment URL where the decision was
  made (`[#N (comment)](https://github.com/<owner>/<repo>/issues/N#issuecomment-…)`).
- **`## Commits`** — used when the PR's structure maps cleanly to its commit list. One line per
  commit: `` - `<short-sha>` <conventional-commit-subject> ``.
- **`## Files`** / **`## Files changed`** — itemized list of files with a one-line reason each:
  `` - **edit** [`<path>`](https://github.com/<owner>/<repo>/blob/<branch>/<path>) — <reason>. ``
  (or `**add**`, `**delete**`, `**move**`). Pre-line a one-shot
  `git diff main..HEAD --stat → N files changed, X insertions(+), Y deletions(-).` summary
  when useful.
- **`## Verification`** / **`## Verified`** — empirical evidence collected before opening the
  PR: test output, command results, tables of measurements. Mark a checked item with a `[x]`
  task-list box or the word `verified`; no glyph, per
  [`no-emoji-in-durable-records`](../../rules/no-emoji-in-durable-records.md).
- **`## Out of scope`** / **`## What's *not* in this PR`** — first-class deferred-items
  section. Each item should link to the follow-up issue (or note it's worth filing).
- **`## Follow-up`** — items surfaced during the PR that deserve their own issue.
- **`## Companion PRs`** — the paired PR in another repository, linked as `owner/repo#N`, with the
  merge order and why.
- **`## Test plan`** — universal closing section (see below).

A dependency-bump PR written by hand may add a `## Composer` section listing each constraint
change (`` `^1.16` → `^1.17` ``).

## Referencing related GitHub issues

Issue references are first-class and have a specific shape. Every PR should reference at
least the issue it closes; many also link blockers, follow-ups, and design-decision comments.

**Closing references** — the issue(s) this PR resolves:

- **Same repo, primary form:** `Closes #N.` — always a trailing period, always `#` prefix.
  Place it either as the very first line of the body (most common) **or** as the very last
  line. Pick one, not both.
- **Cross-repo close:** backticked owner/repo path, linked to the GitHub URL:
  `` Closes [`owner/repo#N`](https://github.com/owner/repo/issues/N). ``
- **Multiple closes:** the keyword is repeated before **every** reference — `Closes #N1.
  Closes #N2.`, or `Closes #N1, closes #N2.` on one line. **`Closes #N1, #N2.` closes only
  `#N1`**, because the keyword pairs with the reference adjacent to it and the parser reads no
  further; `#N2` is an ordinary mention. Measured on `robot-council/core#252`, as a
  before-and-after on one body with nothing else changed: with `Closes #240, #241.` the
  `closingIssuesReferences` field held one node, `#240`; rewritten to `Closes #240. Closes #241.`
  it held both. The first read is not an absence read off a broken query — `#240` came back, which
  is the control that the field and the query work.
- `Resolves #N` is accepted as a synonym but `Closes` is the dominant form — prefer it.

**Cross-references** — issues the PR relates to but does not close:

- **Inline parenthetical:** `tracked in #N`, `tracked separately in #N`, `tracked upstream in
  #N`, `blocked by #N`.
- **In `## Out of scope` / `## Follow-up` bullets:** end the bullet with `— tracked in #N.`
  or `— file as follow-up.` so reviewers can see at a glance whether the residual work has a
  home.
- **Design-decision links:** when a decision was made in an issue comment, link the comment
  anchor, not just the issue.
- **Cross-repo refs without closing:** same backticked owner/repo#N form as cross-repo closes,
  without the `Closes`/`Resolves` verb.

**Do not** bury close references inside `## Files` bullets or test-plan checkboxes — they
live at the top or bottom of the body where a reader expects them.

### Closing keywords — what actually closes an issue

**A keyword fires on the PAIR, not the sentence — negation does not reach it.** GitHub matches a
closing keyword (`close`, `fix`, `resolve` and their `-s`/`-d` forms) adjacent to an issue
reference and reads no further, so the sentence written to *prevent* a close performs one. Use a
form with no keyword in it — every cross-reference form above already is one — or put the number
first:

```
This does not close #999999.        closes it
Superseded; does not fix #999999.   closes it
leaves #999999 open                 safe: no keyword
#999999 stays open for its content  safe: no keyword
```

(`#999999` is a number chosen not to resolve; that, not the code fence, is what keeps these
examples inert.)

**Two code paths decide what closes, and they read different text:**

- **Pull-request linkage** parses the **PR body as Markdown**. A pair in prose links the issue —
  it appears in `closingIssuesReferences` and the sidebar — and closes it when the PR merges into
  `main`, whatever the merge method. Markdown constructs count here: a pair inside a code span or
  fence is not linked.
- **The push-time scan** reads **commit messages that reach `main` as plain text**. No Markdown
  exists there, so a span or fence around a keyword protects nothing.

That split was established in `UAMS-Web/uams-statamic` and `UAMS-Web/wordpress-importer` in
September 2026, where keywords inside a code span and inside a fence closed issues through the
squash commit — those repositories copy the PR body into the squash message — while the linkage
field did not list them. In one case the field listed another issue correctly, which is what made
the unlisted close easy to miss.

**How that maps onto this repository's merge settings** — all three methods are enabled, and each
puts different plain text on `main`:

| Method | Plain text that reaches `main` |
| --- | --- |
| Squash (default message) | Title: the commit's subject for a one-commit PR, otherwise the PR title. Message: the branch commit messages. **Not the PR body**, unless whoever merges pastes it into the editable message. |
| Merge commit | `Merge pull request #N from …` with the PR title as its message, plus every branch commit with its own message. |
| Rebase | Every branch commit with its own message. |

So the PR body is read by the Markdown path, and the branch commit messages and PR title by the
plain-text path. A body-only check misses half of it, and a span is not a defense in either place:
reword.

**A pair can also form ACROSS A LINE BREAK.** A newline is whitespace to the parser, so a line
ending in a keyword pairs with a line beginning with a reference, and a blank line or indentation
between them does not help. In the recorded instance (`UAMS-Web/uams-statamic`, 2026-09-15) the
keyword was a `closed` cell at the end of a status-column row and the reference was the next
row's label; the text said it closed nothing, a grep for `Closes #N` could not match, and
`closingIssuesReferences` was empty, yet the merge closed the issue. See
[`writing-commits`](../writing-commits/SKILL.md) for the table shape to avoid.

**Check both paths before merging.** No CI job covers this, and [`pre-merge-check`](../../rules/pre-merge-check.md) points here for it:
read the field with `gh pr view <N> --json closingIssuesReferences`, then list every
keyword-reference pair in the plain text, same-line and cross-line alike:

```bash
{ git log --format=%B origin/main..HEAD; gh api 'repos/{owner}/{repo}/pulls/<N>' --jq '.title, .body'; } |
  perl -0777 -ne '$n += length; while (/\b(?:close[sd]?|fix(?:e[sd])?|resolve[sd]?):?\s+(?:[\w.-]+\/[\w.-]+#\d+|#\d+|https:\/\/github\.com\/[\w.-]+\/[\w.-]+\/issues\/\d+)/gi) { ($m = $&) =~ s/\s+/ /g; print "$m\n"; $h++ } END { printf "scanned %d bytes, %d keyword-reference pairs\n", $n, $h }'
```

Every printed pair should be an issue this PR means to close, and `scanned 0 bytes` is a failed read
rather than a clean result.

**Make it fail rather than print.** A scan you have to read is one you can merge past, and that has
happened: on `robot-council/core#82` the scan printed `1 fixed: #83` beside the intended
`Closes #75`, from the sentence *"Truncation is filed rather than **fixed: #83**"* — a body saying
the issue was **not** fixed — and the merge closed #83. The output was correct and was not acted on.
So pass the issues the PR means to close and let the check exit non-zero on anything else.

**And it has to refuse in BOTH directions**, which for a long time it did not. A check that
rejects a number it did not expect says nothing about a number it expected and did not get — so the
comma form above passes it: with `intended="240 241"` and a body reading `Closes #240, #241.`, the
scan finds `240`, finds it in `intended`, and exits 0 while `#241` is not linked at all. That
failure is silent in the field too, and surfaces days later as an issue still open after the pull
request that met it merged. Scan to a file, then compare the two sets:

```bash
intended="75"          # space-separated, the issues this PR should close
{ git log --format=%B origin/main..HEAD; gh api 'repos/{owner}/{repo}/pulls/<N>' --jq '.title, .body'; } > scan.txt
test -s scan.txt || { echo "EMPTY READ — not a clean result"; exit 9; }
perl -0777 -ne 'while (/\b(?:close[sd]?|fix(?:e[sd])?|resolve[sd]?):?\s+#(\d+)/gi) { print "$1\n" }' \
  < scan.txt | sort -u > found.txt

bad=0
while read -r n; do
  case " $intended " in *" $n "*) ;; *) echo "UNINTENDED CLOSE: #$n"; bad=1 ;; esac
done < found.txt
for n in $intended; do
  grep -qx "$n" found.txt || { echo "INTENDED BUT NOT CLOSING: #$n"; bad=1; }
done
[ "$bad" -eq 0 ] || { echo "refusing to merge"; exit 9; }
```

Reading from a file rather than a pipe is what makes the loops' findings reach `$bad` at all: a
`while` on the right of a `|` runs in a subshell, so a variable it sets is discarded and an `exit`
inside it leaves only the pipeline's status.

**Then confirm the same answer through GitHub**, because the scan and GitHub are two readers of the
same text and only one of them decides:

```bash
gh api graphql -F owner='{owner}' -F repo='{repo}' -F pr=<N> \
  -f query='query($owner:String!,$repo:String!,$pr:Int!){repository(owner:$owner,name:$repo){pullRequest(number:$pr){closingIssuesReferences(first:20){nodes{number}}}}}' \
  --jq '[.data.repository.pullRequest.closingIssuesReferences.nodes[].number] | sort | join(" ")'
```

**The placeholders go in `-F` values, never inside the query string.** `gh` substitutes `{owner}`
and `{repo}` in `-F` arguments, and does **not** substitute them inside `-f query=…` — a query with
them written inline fails with `Could not resolve to a Repository with the name '{owner}/{repo}'`.
Both measured. That is why this recipe takes variables where the REST ones take a path.

That field reports what the **body** declares, so it is not a substitute for the scan — the plain-text
path reads the commit messages and the title, which the field never sees. Two readers, both checked.

**Run it on every pull request, and read the PR body — not just the commits.** Both halves of that
sentence were paid for on the same day, months after the check above was written.

On `robot-council/cli#2`, a one-line README change, the body opened
*"Closes #1's first housekeeping item, and the last acceptance criterion of …"*. GitHub parses
`Closes #1` and ignores the possessive that follows, so merging closed the repository's **epic**.
Two things let it through:

- **The PR was treated as too small to check.** It changed one sentence in a README. The check is
  cheapest exactly there, and a trivial diff is no evidence about what its body says.
- **A weakened variant was run in its place** — `git log origin/main..HEAD` alone, without the
  `gh api …/pulls/<N> --jq '.title, .body'` half. That commit range contained **no closing keyword
  at all**; the `Closes #1` existed only in the body. A squash merge composes its commit message
  from the PR title and body, so the body is what GitHub reads. Scanning commits is scanning the
  wrong text.

**The possessive is the shape to watch**, because it reads as ordinary prose. Written with a real
number, each of these closes the issue outright: `Closes #<N>'s first item`, `fixes #<N>'s
regression`, `resolves #<N>'s open question`. Write `the first item of #<N>` instead, or reword so
no keyword precedes the reference.

**Those examples carry `#<N>` rather than a number for a reason.** Spelled with real ones, they
would close those issues from this very file's own pull request -- and they did, on the first
attempt at this change: the check flagged `#1`, `#4` and `#9` in the body of the pull request that
adds this paragraph. A document about a trap is written in the trap's own syntax, which makes it the
likeliest place to fall in. The same applies to the commit message, which a squash merge also reads.

## Bullets, headings, tables

- Bullets are `-` only (never `*`). Nest with a 2-space indent.
- **Bold-tag lead-ins** are the standard categorization pattern:
  `- **<noun phrase>** — <prose>`. Use them in `## Files` and any list whose items fall into
  clear buckets.
- **Em-dash `—`** (not a hyphen) separates a bullet's tag from its prose, and joins phrases
  inside prose. Preserve it; don't substitute `--` or `-`.
- **Tables** are used when the data is naturally tabular (matrix cells, config keys, measurements,
  dependency diffs). Standard pipe-syntax, no fancy alignment beyond `---:` for right-aligning
  numeric columns.
- **Do not force-wrap** prose or bullets — let each paragraph/bullet run as one continuous
  line.

## Inline code markup

Backtick anything a developer would type, paste, or grep for:

- File paths — almost always linked, and the URL is the **absolute branch URL**, never a
  relative path: `` [`<path>`](https://github.com/<owner>/<repo>/blob/<branch>/<path>) ``.
  Use `/blob/<branch>/…` for files (`/tree/<branch>/…` for directories), with `<branch>` set to
  the PR's head branch — **even for files already on `main`**. The displayed text stays the bare
  backticked path; only the target is absolute. Vendor file refs include line numbers, e.g.
  `…/PackageServiceProvider.php#L20-L35`.
- Class names, method names, function names — `RobotCouncil\RobotCouncilServiceProvider`,
  `Package::hasConfigFile()`, `configurePackage`.
- Package handles — `spatie/laravel-package-tools`, `orchestra/testbench`, `illuminate/contracts`.
- Version numbers in dependency bumps — `` `13.31.0` → `13.32.0` `` (backticked, joined by a
  literal `→` arrow).
- Env var names, config keys, and CLI flags — `DB_CONNECTION`, `robot-council.members`,
  `--prefer-lowest`, `--dirty`.
- Short shell commands — `composer test`, `vendor/bin/pest --compact <path>`.

Fenced code blocks (with language tags `php`, `yaml`, `bash`, etc.) are reserved for
**context** — vendor source being quoted, config excerpts, command output. Do not use
`diff`-tagged before/after blocks; describe code changes in prose instead.

## `## Test plan` checklist

Every PR ends with a `## Test plan` GitHub task list. Conventions:

- `- [x]` = author has already verified; `- [ ]` = unverified, deferred to reviewer or CI.
- **Include the local gate and a "CI green" item** on every PR. The canonical lines are:
  `- [x] `composer test`, `composer analyse`, and `vendor/bin/pint --test` pass locally.`
  `- [x] CI green: `ci-passed` succeeded on the head commit.`
  Tick the CI line only for the current head; a push after the tick makes it stale.
- Items describe **observable checks**, not implementation steps. Be concrete: name commands,
  test names, config values, and output.
- Imperative mood, often a full sentence or two of context per item.
- Nest sub-checks with 2-space-indented `-` bullets directly under the parent checkbox.
- Include a regression / no-regression item when the change touches shared surface.
- Include a deferred `[ ]` item for anything the author cannot verify locally — for example a
  behavior only a CI cell exercises, such as another operating system or, in the package, a
  `prefer-lowest` resolution.

## Tone and voice

- Technical, prose-driven, detailed where the change needs it; a small fix is fine at ~150 words.
- Lead with the *why*, then the *what*. Fix PRs walk through the root cause before the fix.
- Specific over generic — concrete file paths, line numbers, config keys, commands, measurements.
- Conversational where it helps (`foot-gun the moment someone flips it on`, `is *not* in this
  PR`) but never breezy. No exclamation marks.
- Self-aware about scope. Treat `## Out of scope` / `## Follow-up` as part of writing a good
  PR, not as a failure mode.
- Use `*emphasis*` sparingly — only on words that carry the sentence.
- **Impersonal voice throughout** — every artifact posts under one account, so no `I`/`my`/`you`/`your`, and no vouching stance. See [`impersonal-voice-in-github-artifacts`](../../rules/impersonal-voice-in-github-artifacts.md).
- **No emoji** — not in the title, the body, or any comment. Use words: a bolded clause says what a
  glyph was standing in for, and a glyph breaks downstream where nothing can report it. See
  [`no-emoji-in-durable-records`](../../rules/no-emoji-in-durable-records.md).

## Example shape

A compact fix-PR demonstrating the conventions. **The files are `robot-council/core`'s**, said
plainly because this document is shared and the other repository has no `src/`: read it for the
shape, not for paths to look for. `members()` and the test file are invented.

`````markdown
````
Closes #N.

`RobotCouncil::members()` now returns `[]` when `robot-council.members` is absent from config, instead of passing `null` through its `array` return type and throwing a `TypeError` out of the `robot-council` command before it prints anything.

## The bug

`config('robot-council.members')` returns `null` for a missing key, and the method returned that value unchanged:

```php
public function members(): array
{
    return config('robot-council.members');
}
```

## Files

- **edit** [`src/RobotCouncil.php`](https://github.com/robot-council/core/blob/fix-members-default/src/RobotCouncil.php) — default the lookup to `[]`.
- **add** [`tests/RobotCouncilMembersTest.php`](https://github.com/robot-council/core/blob/fix-members-default/tests/RobotCouncilMembersTest.php) — absent-key and configured-list cases.

## Test plan

- [x] `composer test`, `composer analyse`, and `vendor/bin/pint --test` pass locally.
- [x] CI green: `ci-passed` succeeded on the head commit.
- [x] `vendor/bin/pest --filter=members` covers both paths:
  - With the key absent, `members()` returns `[]`.
  - With the key set to a list, `members()` returns it unchanged.
- [ ] Reviewer: confirm no published-config change is needed for existing installs.

## Follow-up

A key explicitly set to `null` still reaches the `array` return type, because the default applies only to a missing key. Worth a separate issue.
````
`````

(Lede uses the bare `Closes #N.` form. `## The bug` quotes the offending code in a fence, which is
why the proposal block opens with four backticks. Linked file paths point at the absolute branch
URL. Em-dash separates bullet tag from prose. Test plan names the real checks and mixes `[x]`
verified with `[ ]` deferred, with a nested sub-check list. A short `## Follow-up` notes a residual
gap worth its own issue.)
