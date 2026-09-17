# Rule — presenting design-decision forks (lead with a compare/contrast)

When an issue is `hitl` because it carries a genuine **design fork** — an unresolved choice a human must make — or live work surfaces a fork you should not decide alone, **do the analysis first, hand the human a complete, comparable packet, then ask.** Lead with the compare/contrast in the same turn; don't wait to be asked.

This is the *decision* flavor of the `afk`/`hitl` execution-mode labels in [`writing-issues`](../skills/writing-issues/SKILL.md), carried with the `decision-fork` label. An issue is `hitl` when completing it needs a human **decision** (`decision-fork`) or a real-world **action** (`action-flavor`); this rule governs driving a decision to resolution and flipping the issue to `afk`.

## The core principle

A human decides a fork by **weighing trade-offs across dimensions**, not from one-line option labels, so a bare "A or B?" prompt is the wrong deliverable. The packet is the deliverable; the prompt is its last step, and the analysis is never compressed to make room for it.

## The packet — message text, BEFORE any interactive prompt, in this order

1. **The decision at stake, and what's already settled.** Name what is NOT up for grabs — decided constraints, the chosen format, settled neighbors — so nothing settled gets relitigated.
2. **Verified mechanics the comparison rests on.** If it depends on how something behaves, read the code or run it, and state the result. Facts, not speculation.
3. **Trap → reframe.** The naive framing that makes this look harder or more binary than it is, then the reframe that dissolves it.
4. **Per genuine fork, a table and a synthesis.** Rows are the dimensions that actually decide it (fit, security, cost, complexity, failure mode, drift, reversibility); columns are the options; cells stay terse. Immediately after, **one paragraph that ends in a recommendation** — take a position and say why.
5. **What the decision produces** — where it lands (below), and the `hitl` → `afk` flip.
6. **Honest residual** — what stays open or uncertain even after the decision.

## Only then: the interactive prompt

Present the decision with the `AskUserQuestion` tool, **recommended option first and labeled "(Recommended)"**, consistent with the synthesis. One question per genuine fork, all independent forks in one prompt. Keep labels short — the reasoning lives in the packet. "Other" is always available, so don't force a false binary; offer a real third path if one exists. Use the prompt only for a decision that is genuinely the human's: if the issue, the code, or a sensible default resolves it, decide and proceed.

## Where the decision lands, once made

**On the issue.** The GitHub tracker is the system of record, and there is no committed decision log — `/docs` is gitignored, so a decision written there reaches nobody else. In the same change:

1. **Record what was decided, why, and the rejected alternatives** in a `## Decision` section of the issue body, with fuller reasoning in `## Approach` where it is needed.
2. **Reconcile the body** — retire the `## Questions to resolve` / `## Decisions to resolve` section and tick the "decision recorded" acceptance criterion — and **flip `hitl` → `afk`**, removing `decision-fork`.
3. **File the follow-up tickets** the decision produces, plus one for any genuine residual (point to it; don't restate the resolution there).

## A fork ticket delivers the decision and its follow-ups, and nothing else

**Build work does not belong on a fork ticket.** It goes into follow-up tickets blocked by the fork through GitHub's native issue-dependency edges (the REST recipe is in [`github-api-budget`](github-api-budget.md)), not a "blocked by" line in a body.

**The reason is latency, not tidiness.** Fused, the ticket holds one deliverable nobody *can* start, because it waits on a human, and one nobody *should* start, because it sits behind the first. The decision resolves in a conversation and the build in an afternoon, so the fast half silently inherits the slow half's latency, and nothing in the tracker shows it. Observed in `UAMS-Web/uams-statamic#2159`: build work that depended on no decision sat blocked behind a fork for days and moved only once it was split into its own ticket.

**So a fork ticket's acceptance criteria describe a decision being recorded and its follow-ups existing — never a build being done.** A criterion that names a file to change belongs on a follow-up.

## What this is NOT

- Not for an `afk` issue — there is no fork; build to the pinned spec.
- Not the *action* flavor of `hitl`. An issue waiting only on a real-world step (a deploy, provisioning, an account) has no fork to surface, and manufactured "decisions" on it mislead a future agent.
- Not a way to offload routine choices with conventional defaults — decide those and mention them in passing.

## The DRY line

This file is the standing statement of the process. [`writing-issues`](../skills/writing-issues/SKILL.md) owns the `afk`/`hitl` convention and points here for the decision flavor; the `AskUserQuestion` tool description holds the prompt mechanics. Don't restate either.
