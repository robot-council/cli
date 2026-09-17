# Rule — American English is the convention, and a dictionary entry that overrides it needs a stated reason

First-party prose in this repository is written in **American English** — code comments, docblocks, README and other documentation, test descriptions, console command descriptions and output, config comments, view copy, commit messages, and issue, pull-request, and release-note bodies. The arbiter is **Merriam-Webster's first-listed spelling**: `analyze`, `behavior`, `color`, `normalize`, `honored`.

**Nothing enforces this yet.** There is no spell checker in the repository or in CI, so the convention holds only as far as authors and reviewers apply it. Treat a British spelling in a diff as a review finding, not as something a gate will catch.

## Why this is a standing order

Because no tool is checking, the only moment a stray spelling gets caught is while writing or reviewing the change that introduces it. Afterwards nothing reports it.

## How to apply

1. **Fix it in source.** A British spelling in first-party prose gets changed to the American form in the same change. A genuine misspelling gets corrected.
2. **Names are not prose.** A spelling fixed by something outside this repository — a command or subcommand (`phpstan analyse`, and the `composer analyse` script that wraps it), a package name, an API field, a config key, a place name — is a name. Leave it as written.
3. **Quoted external text keeps its original spelling**, exactly as [`impersonal-voice-in-github-artifacts`](impersonal-voice-in-github-artifacts.md) keeps a quotation's original pronouns.
4. **Third-party and vendored prose is out of scope.** A dependency's own spelling is its business.

## If a spell checker is adopted: override discipline

Any spell-check allow-list (a project dictionary, a words file, an ignore-word setting) defeats the check **repo-wide, permanently, and silently**: once a word is listed, nothing reports it again, including in prose nobody has written yet. Each addition is individually defensible and the aggregate is not, and a sorted word list carries no trace of why any entry is there. So:

- **Allow-listing is the last resort.** Decide each flagged word rather than reflexively adding it; a misspelling allow-listed once stays wrong forever.
- **An entry that admits a British spelling needs a stated reason**, and only two qualify: a name that cannot be reworded (point 2 above), or generated or verbatim-quoted external data.
- **Prefer a path exclusion to a dictionary entry** for data files: an exclusion is scoped to the files that need it and visible in config, while an entry is global and invisible. Before adding either, check which mechanism already covers the word — an existing exclusion, or a file type the checker never scans — because an entry justified by a category something else already covers guards nothing while silencing the word everywhere.
- **Record the reason in the pull request that adds the entry**, not in a comment beside the word, which the list's format may read as an entry or not allow at all. `git log -S'<word>' --oneline -- <word-list-file>` finds the introducing commit later.
- **Name a forbidden spelling by reference in documentation about it**; spelling it out adds fresh hits to the very document the moment the entry is removed.

## The DRY line

This file is the standing statement of **the spelling convention and the override discipline for any future allow-list**. Pull-request mechanics belong to [`writing-pull-requests`](../skills/writing-pull-requests/SKILL.md), which should point here rather than restate the convention. It composes with [`no-emoji-in-durable-records`](no-emoji-in-durable-records.md) and [`impersonal-voice-in-github-artifacts`](impersonal-voice-in-github-artifacts.md), which constrain the same prose along different axes.
