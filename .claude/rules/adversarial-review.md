# Rule — adversarially verify before it reaches anyone else

Two things reach other people: **a change that ships, and a claim that is published.** Do not release either on the strength of your own checking alone. First run an **adversarial review** — an independent, skeptical pass whose job is to *break* the thing, not confirm it — then triage its findings, fix the real defects, re-run whatever gate applies, and only then ship it or post it.

- **A change that ships.** You have built something substantive and the checks are green. Review the diff, then commit, open the PR, or merge.
- **A claim that is published.** You have measured, counted, or verified something and are about to put the result in a GitHub artifact others will build on — an issue or pull-request body, a comment, a verification line in a test plan, release notes. Review the claim *and the instrument that produced it*, then post.

**Why this is a standing order, not a nicety.** The author and the author's tests share the same blind spots; an adversary with a fresh, hostile frame does not. A green suite is necessary, never sufficient: it cannot catch the defects it was not written to look for — a test that stays green with its target deleted, a regex that over-matches, a pre-existing bug a new change silently reuses, an injection path no fixture exercises. "Tests pass" earns the build; adversarial review earns the ship.

**The published case is the worse of the two, which is why it is named rather than left implied.** A defect in a diff is caught by a gate *before* it reaches anyone. A defect in a published claim is **already in someone else's reasoning by the time it is found** — they have quoted it, built on it, or stood down because of it. There is no gate between you and them; the post is the gate. Re-auditing your own published output does not substitute: an author tends to miss the same classes on every pass, and the omissions get found by a *different* reader, not by auditing harder.

## How to apply

1. **Trigger — two of them, and you are in one more often than you think.** Apply to any non-trivial, load-bearing, or correctness- or security-sensitive **change before it ships**, and to any **claim before it is published** where someone else could act on it and you checked it with an instrument you also chose. Match against your own activity rather than the word "review": *am I about to state a number, a count, a pass/fail, or an absence that a reader has no way to re-derive?* If yes, it fires, diff or no diff. **An absence is the sharpest case**: "I searched and found nothing" is a claim about your instrument at least as much as about the world. Skip only for genuinely trivial mechanical edits (a doc typo, a pure rename) or work already independently verified. Under **Ultracode** — the session-level opt-in to multi-agent orchestration — this is the default for every substantive slice.

2. **Sequence it: build → review → fix → *then* the gating run.** The review changes the diff, so acceptance evidence gathered before it is evidence for code that is no longer shipping. Do not start expensive validation (a full matrix, a coverage or mutation run, a timing run) until the findings are triaged and fixed; a blocker that rewrites the file under test voids everything launched early. Prepare the PR and docs while the review runs — the review gates the *ship*, not the *build*. The one thing to run early is anything that would change *what you build*, and that is the review itself.

3. **Keep the review agents out of the tree being validated.** Prompt them read-only and mean *writes of every kind*: running the suite is a write (`.phpunit.cache/` and `build/report.junit.xml` per `phpunit.xml.dist`; `--mutate` also writes under `vendor/pestphp/pest-plugin-mutate/.temp/`), so an agent "just checking whether the test passes" can corrupt a concurrent run in the same tree, and the scattered failures read as real regressions. Forbid test execution in the prompt, give the agents `isolation: "worktree"`, or have no validation run in flight, which step 2 already gives you. Commit the diff first so agents review a stable tree.

4. **Mechanism — a multi-agent `Workflow` review** when orchestration is available (Ultracode, or the user asked for a workflow). Spawn **N independent reviewer agents over the diff**, each with a **distinct lens chosen for the change's actual risk surface** — e.g. *regression safety*, *spec / acceptance-criteria correctness*, *edge cases and test rigor*, and a dedicated *security / attacker* lens for a package's high-risk surfaces: what the service provider registers (config, views, migrations, commands), any routes, controllers, or console commands the package adds, config defaults a consumer inherits silently, migrations a consumer publishes and runs, raw `{!! !!}` output in `resources/views/`, and anything that processes consumer- or end-user-supplied input (SSRF, path traversal, injection). Prompt each to **refute**, default to skeptical, and pin what it must **not** relitigate (settled decisions, the chosen format). Force **structured, schema-validated findings** (severity, a worked example, a wrong-vs-right outcome verified against the code). Launch it in the background, under step 2. Without orchestration, scale down to the same adversarial frame: `/code-review` for general correctness, `/security-review` for a security-sensitive diff (or the whole-package [`security-audit`](../skills/security-audit/SKILL.md) skill), or a focused self-review hunting your own blind spots.

5. **Make the review real, not theatrical.** Every finding is **verified against the code** (ideally reproduced by running it), never speculated — a claimed defect shows the concrete input and the wrong vs. right behavior. Scale lens count and verification votes to the risk.

6. **Triage and close the loop.** Fix every **blocker / major** and every cheap, high-confidence **minor**. For a real bug fixed, add a regression test and **mutation-verify** it: disable the fix, the test must go red; re-enable, green. **Revert by copying the file aside, never with the stash** — `cp src/Foo.php "$SCRATCH/Foo.fixed.php"`, `git show HEAD:src/Foo.php > src/Foo.php`, run, restore from the copy. The stash stack is shared by every worktree of the repository, so a failed `stash push` followed by `stash pop` applies somebody else's entry (see [`worktrees`](worktrees.md)), and `git checkout --` throws away uncommitted work. Read the *reason* the test went red: a negative control that fails for the wrong reason proves nothing, and a shell one-liner can corrupt the file instead of editing it, which reads as a very convincing red. `--mutate` automates the same check across the changed surface (see the tooling section). A **pre-existing** bug the change merely touches or reuses is in scope — fix it. **Document** accepted gaps and deferred residue by name, never silently. **Re-run the gate** after the fixes — `composer test`, `composer analyse`, `vendor/bin/pint --test` locally, then the GitHub Actions checks on the pushed branch. Only then ship.

7. **Record the verdict.** A closed design fork goes on the issue per [`design-decision-forks`](design-decision-forks.md). A new open question or unspecified detail the review surfaced becomes a follow-up issue per the [`writing-issues`](../skills/writing-issues/SKILL.md) skill. Otherwise, a docblock or PR-body note.

## Tooling that raises the floor (so the review can reach the ceiling)

Review is irreplaceable for *novel* reasoning (a threat model, "is this structure sound?"); two cheap, durable tools catch the **mechanical** half, so the review's attention goes where only a reviewer can look.

- **Mutation testing — Pest's `--mutate`** (`pestphp/pest-plugin-mutate`; the version-specific lines below were read from v5.0.2, and `composer.lock` is not committed, so check `composer show pestphp/pest-plugin-mutate` first). It mutates your code and demands a test fail; a **surviving mutant is a false-green test**. Run it diff-scoped — `vendor/bin/pest --mutate --path=src --class="RobotCouncil\<ChangedClass>"` — and close each survivor. **`0 Mutations for 0 Files created` means it found nothing to mutate, not that you passed**; it exits 0 unless a minimum score is set, so treat it as a setup failure. A hand-rolled mutation has the matching trap: a `sed`/`perl` substitution whose escaping never matched leaves the file untouched and reports as a survivor, so **assert the edit changed the file before reading the result.** Driver setup and invocation traps live in [`pcov-setup`](../skills/pcov-setup/SKILL.md).

  - **The survivor criterion cannot see a false kill, and that asymmetry is the point.** A false **survivor** leaves something to explain, so reading the survivor list catches it. A false **kill** leaves **nothing to explain**, so the criterion is *satisfied* by it — and v5.0.2 records any child process that exits unsuccessfully as killed (`MutationTest::hasFinished()`), whether or not the covering test ran. **The control is step 6's negative control turned on the tool:** skip the single covering test, confirm it is genuinely skipped, and require the mutant to flip to survivor.

    | trap | direction | what it looks like |
    | --- | --- | --- |
    | a `sed`/`perl` mutation whose escaping never matched | **false survivor** | a mutant nobody killed, because the file was never edited |
    | an inert `@pest-mutate-ignore` (below) | **false survivor** | a mutant that keeps surviving despite the annotation |
    | an equivalent mutant | **true survivor** | no input can kill it; a reason to write down, not a defect |
    | mutant children that never ran the tests (`UAMS-Web/uams-statamic#2322`, Windows) | **false kill** | every mutant killed, `100.00%`, exit 0 |
    | one mutant misreported inside a real run (`UAMS-Web/uamswp-migration-api#179`, Windows) | **false kill** | a plausible score with one entry missing from the survivor list |
    | `0 Mutations for 0 Files created` | **neither** | no population, so no verdict of either kind |

    Survivor rows are caught by reading the survivor list, false-kill rows only by the control above, and the last row by neither.

  - **The criterion is *no unexplained survivors*, not a score.** A percentage merges an unexamined survivor with a provably equivalent one, and a high threshold turns "explain this survivor" into "delete a defensive guard to move the number". Every survivor is **killed** (a test to write), **deleted** (dead code), or **annotated with the reason it cannot be killed**, where the survivor is.

  - **The annotation is `// @pest-mutate-ignore`, per line and optionally per mutator** (`// @pest-mutate-ignore: InstanceOfToTrue`) — not the config-level `ignore()` / `except()`, which silence a whole path or a mutator everywhere and cannot carry a per-survivor reason. **Put the reason on a preceding line, never on the marker's.** In v5.0.2's `Support/NodeVisitor.php` the regex `@pest-mutate-ignore(.*)` captures the rest of the marker's line; an empty capture suppresses everything, otherwise it is split on `,`, each part trimmed of `' */:'`, and compared against mutator names. Measured on macOS on 2026-09-17 by driving the vendored v5.0.2 generator over a probe `if ($x > 1)`:

    | marker on its own line above the node | suppresses (in the node's descendants — see placement) |
    | --- | --- |
    | `// @pest-mutate-ignore` | every mutator |
    | `// @pest-mutate-ignore: GreaterToGreaterOrEqual` | that mutator |
    | `// @pest-mutate-ignore: GreaterToGreaterOrEqual because phpstan needs it` | **nothing** — the tail is not a mutator name |
    | `// @pest-mutate-ignore: GreaterToGreaterOrEqual, because phpstan needs it` | that mutator — the **comma** splits the prose off |
    | `// @pest-mutate-ignore` plus one trailing space | **nothing** — the capture `' '` is not empty |
    | one-line `/** @pest-mutate-ignore */` | **nothing** — the capture `' */'` is not empty |

    ```php
    // phpstan mandates this guard, so InstanceOfToTrue cannot be killed without
    // failing the other gate.
    // @pest-mutate-ignore: InstanceOfToTrue
    if ($x instanceof Foo) {
    ```

    The trailing-space row makes formatting load-bearing: Pint strips trailing whitespace from such a comment (verified on v1.32.1 with the Laravel preset) and `.editorconfig` sets `trim_trailing_whitespace`, so a formatting-only commit can turn an inert bare marker into a suppress-everything one.

  - **Placement decides which mechanism reads the marker** (v5.0.2, same probe). **Above** a node, it attaches as a php-parser comment and stops traversal of the node's *children*, but the mutation is applied in `leaveNode()`, which still runs for the annotated node itself — so a mutator targeting that node (`RemoveArrayItem` on an array item, `IfNegated` on an `if`) is **not** suppressed. **Trailing** the node's first line, it is read by a line map built with `explode(PHP_EOL, $contents)` (`Support/MutationGenerator.php`), which fails when the file's line endings lack the runtime's `PHP_EOL` (under Windows PHP an LF file collapses to one line, read from source and not measured; a CRLF file under macOS PHP measured fine), and whose per-line variable is never reset, so a bare trailing marker after any `: Mutator` marker in the file inherits that earlier list. Annotate above for an operator inside the statement. For a mutator on the node itself, a trailing marker did suppress it in the macOS probe, but under Windows PHP it depends on the checkout's line endings (`.gitattributes` sets no `eol`), so prefer killing it or restructuring.

- **Property-based / round-trip tests** for pure cores — parsers, mappers, normalizers, anything with an inverse: assert the load-bearing **invariant** over many generated inputs instead of three examples (`decode(encode(x)) == x`, "no input slips past the sanitizer"). Use a fixed seed so the run is deterministic and still broad.

Both raise the floor cheaply and forever; neither invents the threat model.

## The DRY line

**This file owns whether a thing has been adversarially checked before it reaches anyone — diff or claim.** [`measurement-parity`](measurement-parity.md) owns whether a number is *comparable* to a prior one, a question about the harness rather than about who checked it; [`long-running-commands`](long-running-commands.md) owns bounding and attributing a process; [`pcov-setup`](../skills/pcov-setup/SKILL.md) owns the coverage driver and the `--mutate` invocation traps. The `Workflow` tool description holds the orchestration *mechanics* — don't restate them. [`design-decision-forks`](design-decision-forks.md) surfaces genuine forks *before* building.
