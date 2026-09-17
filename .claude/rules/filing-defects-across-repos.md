# Rule — file a defect in the repository that owns it

A defect found in a dependency — a Composer package, a GitHub Action, a tool — or in any other repository gets **an issue in that repository**. Not a note in a pull-request body, and not a comment on an issue that is already closed.

## Why this is a standing order

**Because the alternatives are not tracking artifacts.** A pull-request aside is read during review and never again. A comment on a *closed* issue is worse: nothing surfaces that thread a second time, so the finding lands in the one place guaranteed not to be revisited. Both feel like recording the problem, and neither is. Unmet acceptance criteria raised as a comment on a closed issue still need a ticket of their own before anyone will meet them.

## How to apply

1. **Route by ownership.** The issue goes in the repository whose code, docs, or behavior is wrong. A finding about a dependency belongs to the dependency even when it surfaced here; the local record is a link, not a copy.

2. **Check before filing, and check the shipped artifact.** Search the target tracker with several phrasings and **include closed issues** — a closed one still means do not refile, per the duplicate check in [`writing-issues`](../skills/writing-issues/SKILL.md). Then read the *released* copy rather than a checkout of the upstream source: a fix may already have shipped in a version nobody here has adopted, and a defect reproduced against an authoring tree may not exist in what people are running. `composer.lock` is not committed in this repository, so the version in your `vendor/` is whatever your last install resolved — CI and a fresh install can resolve a different one.

3. **Cross-link adjacent issues in the issue bodies.** Checking for duplicates does nothing about two legitimately distinct issues that edit the same lines — say, one gating whether a block runs and another fixing what it outputs, filed minutes apart. Say so in both bodies. A remark anywhere else reaches whoever is reading now; the body reaches whoever picks the work up next month.

4. **Record what was measured and under what conditions — and for a dependency, the REVISION, because a version string does not identify code.** Name the version (`composer show <package>`), the platform, and the surface the observation came from, and state the bounds you did not test — the same discipline [`measurement-parity`](measurement-parity.md) applies to timings. A finding whose conditions are not written down is generalized past its evidence by the next person to act on it, including whoever writes the fix.

   **A version and a source reference can both match while the files differ**, so hash the file you are calling into (`shasum -a 256 <path>`) and cite that alongside the version. A cross-repository report is read by someone who cannot see your tree, so an unqualified "present in `<package>`" is incomplete the way an absence without a search scope is incomplete, and it is the maintainer who pays for it. The evidence behind this lives in [`measurement-parity`](measurement-parity.md).

## What this is not

Not a reason to file upstream instead of fixing something here. If the defect is ours, fix it. This rule governs where a report lands when the fix is not ours to make.

Nor does it apply to residue from a ticket in this repository — [`closing-a-ticket`](closing-a-ticket.md) already requires that to become an issue rather than a mention, and this rule is the cross-repository case of the same instinct.

## The DRY line

This file owns **which repository a defect is reported to, and what the report must pin down**. How to *write* the title and body belongs to [`writing-issues`](../skills/writing-issues/SKILL.md); the voice those bodies are written in to [`impersonal-voice-in-github-artifacts`](impersonal-voice-in-github-artifacts.md); which API surface to spend to [`github-api-budget`](github-api-budget.md). None of those are restated here.
