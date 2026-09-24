# Rule — a green check is not a merge decision

The required check answers one question: **does the change pass tests, analysis, and style on the state it will merge into?** Merging asks a different one: **should this land on `main`?** No check can answer that. Before merging any pull request, work the three steps below.

## What the checks already enforce

The `main` ruleset requires every change to arrive through a pull request, requires the `ci-passed` check to succeed, and requires the branch to be up to date with `main` before it merges. GitHub also refuses to merge a draft. `ci-passed` is the last job of [`.github/workflows/ci.yml`](../../.github/workflows/ci.yml) and succeeds only when every other job succeeded: the SQLite test matrix, the `postgres` test job, PHPStan, `pint --test`, and `rector --dry-run`. The last two fail rather than fixing. On a pull request those jobs run against GitHub's merge of the branch into `main`, and the up-to-date requirement keeps that merge current.

So bringing the branch current, trial-merging, running the gate, and recording the result are done by the ruleset and the checks panel, not by hand. **Do not merge around them**, and do not treat a `ci-passed` from an older head commit as covering the current one.

**Confirm the enforcement is real before relying on it.** `gh api 'repos/{owner}/{repo}/rulesets' --jq '.[] | "\(.name) \(.enforcement)"'` must show the ruleset as `active`. While it is not, nothing above is enforced, and confirming a successful `ci-passed` on the current head of a branch that is current with `main` is your job again.

## Why this is a standing order

**Because everything the checks cannot see is still unchecked when they go green.** No job reads an issue's acceptance criteria, knows that a change needs a matching change in another repository, or notices that a branch is correct on its own but wrong alongside something merged since. A green panel is necessary and says nothing about any of those.

## The check

1. **Check the acceptance criteria against the diff, one at a time.** Not against the pull-request body's claims about the diff. A criterion that will not be met is named and explained rather than left silently unchecked, per [`closing-a-ticket`](closing-a-ticket.md). A criterion that is already true without any work in the diff is a criterion to rewrite, not to tick.

2. **Account for companion work.** If the change needs a matching change elsewhere, such as in a consuming application or another repository, confirm that change exists and state the order the two land in. A pull request that is green on its own can still be half of a change.

3. **Adversarially review what is actually merging.** The [`adversarial-review`](adversarial-review.md) pass saw the branch, not the branch plus everything that has landed since. Read the diff **as it will exist on `main`**, and look for interactions with changes merged since, shared inputs ([`sync-pr-branch`](sync-pr-branch.md) lists them), and conventions the branch now violates. If the base has not moved, say so; the earlier review then covers this step.

## The DRY line

This file owns **the judgment half of the decision to merge**. The mechanical half belongs to the `main` ruleset and `ci.yml`. What a merge will close, which no check covers, is checked per [`writing-pull-requests`](../skills/writing-pull-requests/SKILL.md). Bringing a branch current and the inputs its checks read are [`sync-pr-branch`](sync-pr-branch.md); ship-time obligations are [`closing-a-ticket`](closing-a-ticket.md); the skeptical pass itself is [`adversarial-review`](adversarial-review.md).
