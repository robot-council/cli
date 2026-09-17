---
name: writing-commits
description: >-
  Commit message conventions: Conventional Commits prefix (`feat:`, `fix:`, `docs:`,
  `refactor:`, `perf:`, `style:`, `test:`, `build:`, `ci:`), 50-character subject limit
  including the prefix, no forced body line wrapping, single blank line between paragraphs,
  unordered `-` bullets with nested indented bullets, inline code markup for technical terms,
  no closing keyword at a line end above an issue reference, and the final commit message
  presented in a Markdown code block. Also covers what to stage — explicit paths, never
  `git add -A`. Activate whenever drafting, rewriting, or critiquing a commit message —
  including in-place edits, PR descriptions derived from commit messages, or rewording an
  existing commit — and whenever staging changes for a commit.
---

# Writing Commits

This skill defines the standards for commit subject and body so messages stay consistent and
readable across the project.

## Output format

When proposing a commit message, **present the final message inside a Markdown code block** so
the user can copy it cleanly.

## Subject line (first line)

- Follows **Conventional Commits** (https://www.conventionalcommits.org/en/v1.0.0/).
- Single line, **≤50 characters total**, *including* the Conventional Commits prefix.
- Succinctly describes the change.

### Allowed prefixes

| Prefix | When |
| --- | --- |
| `build` | Changes that affect the build system or external dependencies (e.g. `composer.json` constraints). |
| `ci` | Changes to CI configuration (the GitHub Actions workflows under `.github/workflows/`, `.github/dependabot.yml`). |
| `docs` | Documentation-only changes. |
| `feat` | A new feature. |
| `fix` | A bug fix. |
| `perf` | A code change that improves performance. |
| `refactor` | A code change that neither fixes a bug nor adds a feature. |
| `style` | Changes that don't affect code meaning (whitespace, formatting, missing semicolons, etc.). |
| `test` | Adding missing tests or correcting existing tests. |

Dependabot writes its own commit subjects, which do not follow this convention.

## Body

- **Do not wrap** body text or list items with forced line breaks. Let each paragraph or bullet
  run as one continuous line unless a newline is required for structure (between subject and
  body, between paragraphs, or before a new list).
- Separate paragraphs with a **single blank line**.
- **Unordered lists** with `-` for bullet points, detailing specific changes. Nest sub-items
  with indented `-` bullets directly under their parent. **No blank line** between consecutive
  list items at the same level, and no blank line between a parent and its child.
- Use **inline code markup** (`` `code` ``) for technical terms — class names, config keys,
  file paths, method names.
- Clear, readable explanation of the change, its impact, and any relevant context.
- **No trailing newlines** or unnecessary spacing.
- **Never let a line END in a closing keyword when the next line BEGINS with an issue reference.** A newline is whitespace to GitHub's closing-keyword parser, so the two are read together as a directive nobody wrote, and the commit closes that issue when it reaches `main`. The keyword does not have to be a verb anyone chose — a **status-column table** puts a bare `closed` at the end of one row and the next row's `#N` label directly beneath it:

  ```
  #999998  write the patch                       closed
  #999999  file the bug upstream                 retitled, action-flavor added
  ```

  A grep for `Closes #N` cannot see this, because the pair exists only after the join. Break the adjacency — reorder so the status cell is not last, or write the cell as something other than a bare keyword (`now closed`, `done`). For this trap the branch commits are the input that reaches `main` here under every merge method: this repository's squash merge builds its commit message from them by default, and merge and rebase merges land them as written. The mechanism, a scan that reads the joined text, and how each merge method maps onto it are in [`writing-pull-requests`](../writing-pull-requests/SKILL.md).

## Tone and voice

- **Already impersonal, and stays that way** — an imperative subject and a `-` bullet body carry no narrator, so commits satisfy the [`impersonal-voice-in-github-artifacts`](../../rules/impersonal-voice-in-github-artifacts.md) rule by construction. Keep it that way rather than treating it as exempt.
- **No emoji**, per [`no-emoji-in-durable-records`](../../rules/no-emoji-in-durable-records.md).

## Example shape

Illustrative only; the method and test file are invented to show the shape.

````markdown
```
fix: default council members to an empty array

- Default the `robot-council.members` lookup in `RobotCouncil::members()` to `[]`
- Keep the configured list unchanged when the key is present
    - Covers an unpublished config file
    - Covers a published config without the key
- Add Pest coverage in `tests/RobotCouncilMembersTest.php` for both paths
```
````

(Subject ≤50 chars including `fix:`. Body uses `-` bullets, nested children directly under
their parent without blank lines, and inline code markup for config keys and class names.)

## Staging — name the paths, never `git add -A`

**Stage explicitly: `git add <path> <path>`.** `git add -A` / `git add .` / `git commit -a` sweep in whatever else the working tree holds, where it is easy to miss in review and hard to attribute later.

The test suite itself is not the usual source. Its outputs land in ignored paths — `build/` (the JUnit report from `phpunit.xml.dist`, PHPStan's `build/phpstan` temp directory), `.phpunit.cache`, `coverage/` — and Testbench's skeleton application lives under `vendor/`. `composer.lock`, `phpunit.xml`, `phpstan.neon`, and `testbench.yaml` are ignored too, so local overrides stay local. What does leak is tooling that rewrites **tracked** files beyond your change:

- **`composer format` / `vendor/bin/pint` with no path** reformats every PHP file that deviates from the preset, not only the ones you touched. `vendor/bin/pint --dirty` limits it to uncommitted files.
- **`composer refactor` / `vendor/bin/rector`** rewrites every file under `src/` and `tests/`, and `rector.php`, that a rule matches, not only the ones you touched. Stage its changes deliberately, or run `composer test:refactor` to see them first.
- **`vendor/bin/phpstan analyse --generate-baseline`** writes a `phpstan-baseline.neon` holding every current error, including ones your change did not introduce. The project has no baseline; fix the errors instead.
- **`composer require … --no-update`**, the way the CI `tests` job pins a Laravel and Testbench version, edits `composer.json`. Reproducing a CI matrix cell locally leaves that edit behind.
- **Untracked scratch** — notes, fixtures, and generated files accrete over a branch's life, and nothing in `.gitignore` covers them.

So the sequence before every commit:

```bash
git status --short              # read it; know why each line is there
git add <the paths you changed>
git diff --cached --stat        # confirm the staged set is exactly your change
```
