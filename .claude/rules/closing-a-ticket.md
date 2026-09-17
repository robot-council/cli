# Rule — finish a ticket where it is visible, not just where it builds

Work is not done when the code works. It is done when the branch has merged, the acceptance criteria are ticked or their absence is explained, any board card has moved, and whatever the work surfaced but did not do has a ticket of its own. Each of those is a place where a finished change quietly stops being legible to anyone else.

**Why this is a standing order.** The failure is never loud. A change that ships with four criteria left unchecked looks identical to one that met them; the ticket sits there implying work remains, or gets closed implying work happened. Residue that never gets a ticket is indistinguishable from residue nobody noticed. None of these break a build, which is exactly why they need a rule.

## How to apply

1. **Never push to `main`.** Branch, open a pull request, and let it merge — for a one-line docs fix as much as a feature, and when you are the only person working. The `main` ruleset refuses a direct push while it is `active`, but it can be disabled. A local commit with no branch is fine while nothing ships; a push to `main` is not.

2. **Tick acceptance criteria as you meet them, not in a sweep at the end.** A criterion ticked while the work is fresh records what actually satisfied it. A sweep at the end records what you remember.

3. **An unmet criterion is named, never silently left.** If something in the list will not be met by this change, say so on the ticket and say why: superseded by a decision, deferred to a follow-up (with its number), or found to be wrong when the work met reality. Silently unchecked boxes leave the next reader unable to tell which of the three happened. Where the criterion was wrong, fix the criterion — the acceptance criteria are the specification.

4. **If the work is tracked on a project board, move the card in step with the branch** — and check the card exists first. An unboarded ticket satisfies "no card is out of step" **vacuously**, so a missing card looks exactly like a correct one. A card that moves a week late is a card nobody trusted.

5. **Residue gets a ticket, never a mention.** Anything the work surfaced and did not resolve — a defect found in passing, an unspecified detail, a decision that turned out to be needed, a cleanup the diff made obvious — earns a GitHub issue per [`writing-issues`](../skills/writing-issues/SKILL.md), linked from wherever it was surfaced. "Worth following up" in a pull-request body is a note read once, not a ticket. Review questions ([`adversarial-review`](adversarial-review.md)), design forks ([`design-decision-forks`](design-decision-forks.md)), defects owned elsewhere ([`filing-defects-across-repos`](filing-defects-across-repos.md)), and out-of-scope items ([`writing-pull-requests`](../skills/writing-pull-requests/SKILL.md)) are the specific cases of this.

6. **Close the ticket, or say why it stays open.** A merged pull request with `Closes #N` in its body does this for you, which is the reason that line is mandatory. When work merges without closing its ticket — a slice of a larger deliverable — the ticket says what remains.

## What this is not

Not a checklist for the end: steps 2 through 5 happen *during* the work, and only the sixth is terminal. Nor a merge gate: whether the change is *correct* is [`adversarial-review`](adversarial-review.md), [`pre-merge-check`](pre-merge-check.md), and the GitHub Actions checks; this rule is about whether it is *legible* once it is.

## The DRY line

This file owns the **ship-time obligations** that outlive a green build. Where you branch is [`worktrees`](worktrees.md); whether a branch may merge is [`pre-merge-check`](pre-merge-check.md); how a ticket, a pull request, or a commit is *written* is [`writing-issues`](../skills/writing-issues/SKILL.md), [`writing-pull-requests`](../skills/writing-pull-requests/SKILL.md), and [`writing-commits`](../skills/writing-commits/SKILL.md). None of those are restated here.
