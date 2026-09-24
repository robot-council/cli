# Rule — a result is not evidence until the instrument has been shown to tell the two answers apart

**A check that returns nothing has told you about the check, not about the world.** Before reading silence as an absence (no matching process, no duplicate issue, no leaked string, no failing test), show the same instrument, unchanged, finding something it is known to be able to find. Until then, "found nothing" and "could not have found anything" are byte-identical.

**The same holds for a result that is not empty.** An instrument can return a well-formed, plausible, wrong answer, and proving it can produce *a* result does not catch that. Both halves come down to one requirement: **do not read an instrument's output until it has been shown to tell apart the two answers it is being asked to choose between.** The file is named for the empty case, where this was first paid for, but the rule is the wider one.

## Why this is a standing order

The two outcomes are indistinguishable **by construction**. There is no error to read, and a broken check fails in the reassuring direction: it reports *clean*, so it is believed, quoted, and built on. Instances on record include a process sweep that reported a machine idle while four runs were live, a duplicate check that could not see pull requests, an audit whose expression could not match its own defect, and a paginated query whose page-one count read as the total. **The non-empty half is more dangerous.** An empty result at least prompts *did that actually run?* A number in the expected format and range closes the question instead.

## How to apply

1. **Run the positive control before you read the empty result.** Use one case the instrument must find, with the same command, flags, and quoting. If that is empty too, you have learned the instrument is blind and nothing about your question.

2. **State what the control could have failed on.** A control that exercises a different variable than the claim is decoration. "The query found eight other labels" says nothing about a read window that excludes the one you asked about.

3. **Prefer a control the instrument cannot pass by accident.** A synthetic fixture containing exactly what you are hunting beats a real artifact that may have been fixed since.

4. **Distinguish "found nothing" from "could not run."** A failed command prints nothing on stdout, and a pipeline hides its status. Check the exit code, or print a count rather than only matches.

5. **A passing control proves detection, not coverage.** It shows the instrument can see *one* thing, not everything of that kind. Report the bound, not the reassurance.

6. **For a positive result, you need a NEGATIVE control, and a passing positive control does not supply it.** A positive control shows the instrument *can match*; a negative control shows it *does not match what it should not*. An instrument that gives the same answer for both has told you nothing, in a well-formed way.

   **Worked example.** The question was whether a pull request's `Closes #N` had registered as a closing link. GitHub's timeline `cross-referenced` event looks like the answer, but on `UAMS-Web/uams-statamic#702`, an issue referenced only by a deliberately non-closing `Refs`, it returned **15** such events (recorded 2026-09-09). It fires for closing links, bare mentions, and prose alike, so it cannot discriminate. The instruments that can are `closingIssuesReferences` plus a check that the keyword sits next to the reference, and both are narrower than they look:

   - **`closingIssuesReferences` reports what a pull request DECLARES, not what its merge does.** Measured in `UAMS-Web/uams-statamic` on 2026-09-16: a merged pull request with an empty field closed an issue anyway, while a control's field correctly listed its closed issue. The control is what makes the empty read an absence rather than a broken query. The field parses the body as Markdown, while the push-time scan reads the commit text that reaches the default branch as plain text, where fences and code spans disarm nothing.
   - **"Next to" spans a newline.** A keyword ending one line pairs with a reference starting the next, so a line-by-line check reports clean on exactly that shape.

   This was paid for, not reasoned out. The pull request there that added a cross-line scanner was checked before merging by reading `closingIssuesReferences`. The field listed its one intended ticket, and the merge closed a second. The merge-time procedure is step 8 of [`pre-merge-check`](pre-merge-check.md).

7. **Scale the rigor to what the answer would license, not to how hard the measurement looks.** A cheap check whose result will close a ticket, stand someone down, or feed someone else's decision earns a control. In the sharpest instance on record, a file-age comparison between two different clocks read a 32-second-old file as five hours old. It asserted that other people's work had been wasted, and someone acted on it before it was corrected.

8. **Read the object, not an aggregate computed over it.** A count, a diff stat, a `total_count`, or a filtered boolean discards exactly the detail that would have shown the answer was wrong. The direct read is usually cheaper and correct.

## Worked instances of a well-formed wrong answer

These were measured in `UAMS-Web/uams-statamic` sessions, and every one looked correct.

| instrument | returned | what was true | how it was caught |
| --- | --- | --- | --- |
| a count of `catch` blocks per version, to decide whether a fix was present | a difference in the right direction | the fix was in one function; a mere refactor would have produced the same count | another reader asked which function |
| `stat -f %Sm` (local time) against a `date -u` "now" | a 32-second-old file read as 5 hours old | the two sides used different clocks | re-derived from epoch seconds |
| a duplicate check run against issues only | no conflicting work | an open pull request on the same file, which `repos/{owner}/{repo}/issues` returns and `gh issue list` filters out | another reader named the pull request |
| a one-line count of open issues versus pull requests | `prs=0` | `prs=5`, by direct membership test | a membership test run for another reason |
| a project-board read with an explicit page size | exactly 400 items, missing both issues sought | the board held 1,982; 400 was the limit | the two issues were known to exist |

**Truncation at exactly the requested size looks like a complete answer**, and the number that would reveal it, the total, is the one not printed.

## A second shape: the instrument answers a narrower question than the one asked

| mechanism | the question asked | the question actually answered |
| --- | --- | --- |
| a read from a local clone | does this file exist in the repository | did it exist as of my last fetch |
| a read of one branch | does this repository contain X | does this branch contain X |
| a contents-API read with no `?ref=` | does this repository contain X | does the default branch contain X |

Five independent readers made this error within twenty minutes, by these three mechanisms. That is a property of the instruments, not five lapses. **The defense is naming the scope in the same sentence as the result**: "absent on `main` as of this fetch," not "absent." An absence is a claim about a boundary, and an unstated boundary is assumed to be the widest.

## The DRY line

This file states **the general form and its remedy**, and owns nothing else. Specific cases live where they apply: the six causes of a blind process sweep in [`long-running-commands`](long-running-commands.md), API query limits in [`github-api-budget`](github-api-budget.md), what a merge will close in [`pre-merge-check`](pre-merge-check.md), and the case for an independent checker of an author's own audit in [`adversarial-review`](adversarial-review.md). This file is for the reader whose situation is none of those.
