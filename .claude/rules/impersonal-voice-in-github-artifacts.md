# Rule — write GitHub artifacts in an impersonal voice

This repository is worked from a **single account**, although the `robot-council` organization owns it, and the same is true of its sibling. Every pull request, issue, and comment posts under that one account: the same account authors the change, files the ticket, reviews the branch, and posts the validation comment. So **first person reads as that person narrating their own work, and second person reads as them addressing themselves** — to anyone who opens the tracker. Neither is what the sentence means.

Write the artifact as a statement about runs, files, and tickets. There is no narrator in it.

## Why this is a standing order

The defect was found in `UAMS-Web/wordpress-importer` on 2026-08-24, where a sweep of validation comments under a single posting account found 72 of 82 needing a rewrite. It then sharpened, and the sharpening is the half worth keeping: **deleting the pronoun is not enough — the vouching stance has to go with it.** "All three defects are confirmed from use" contains no pronoun and still reads as the author confirming their own PR. A rule that stops at `I`/`you` catches the easy half and leaves the sentence that actually misleads.

## How to apply

1. **Scope: PR bodies, issue bodies, and PR/issue comments.** Bodies have skills — [`writing-pull-requests`](../skills/writing-pull-requests/SKILL.md) and [`writing-issues`](../skills/writing-issues/SKILL.md). Comments are the largest class by volume and no skill covers them, which is why this is a rule.

2. **No `I`, `my`, `you`, or `your` — state the finding as a fact about a run or an artifact.**

   | Instead of | Write |
   | --- | --- |
   | "I verified X" | "Verified: X" |
   | "my #N run" | "the #N validation run" |
   | "you found the reason I did not" | "the reason recorded here is stronger than the one raised on #N" |

   The replacement is almost always shorter, because the pronoun carried no information.

3. **Remove the confirming narrator, not just the pronoun.** If a sentence implies a party who checked, corroborated, agreed, or was persuaded, it is wrong. Replace the vouch with the evidence it stood in for: "All three are confirmed from use" becomes "Each has a matching failure on record: …"; "Verified independently" becomes "Verified: #N open, #M closed". **Praise is the same defect** — "good catch", "the sharpest sentence in the PR" read as self-congratulation under one account and tell a reader nothing. State what the sentence establishes instead.

4. **Impersonal does not mean vague.** Keep every number, path, ticket reference, command, and error string. "Verified: X" is impersonal; "this was checked" removes the source along with the pronoun and is worse than what it replaced. If deleting the narrator leaves nothing behind, the sentence had no content — cut it or go find the evidence.

5. **Two carve-outs, and they are real.**
   - **Repo prose deliberately addresses a reader as "you".** `.claude/**` and [`README.md`](../../README.md) are instructions to whoever reads them next, and second person is correct there. A sweep must not strip it; this file does it in the sentence you are reading.
   - **Verbatim quotations keep their original wording**, including "you". A quote is evidence; altering it to satisfy the rule is a worse defect than the quote.

Commit messages already comply by construction — an imperative subject and a `-` bullet body per [`writing-commits`](../skills/writing-commits/SKILL.md). Keep it that way: a bullet that starts "I moved…" is the same defect arriving through a different door.

## The DRY line

This file is the standing statement on **voice in GitHub artifacts**. What goes *in* a body belongs to [`writing-pull-requests`](../skills/writing-pull-requests/SKILL.md) and [`writing-issues`](../skills/writing-issues/SKILL.md), and commit mechanics to [`writing-commits`](../skills/writing-commits/SKILL.md); each points here rather than restating this. It composes with [`no-emoji-in-durable-records`](no-emoji-in-durable-records.md), which governs a different leak in the same sentences, and with [`adversarial-review`](adversarial-review.md), whose findings are exactly the sentences most tempted into a vouching stance.
