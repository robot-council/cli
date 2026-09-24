# Rule — mirror the *job*, not the command, before comparing to a baseline

Before you compare a measurement against a prior one, such as a suite time, a CI pass/fail, or a figure quoted in an issue, **read the harness that produced the baseline and reproduce its whole job**, not just its headline command. A number measured under different setup is not a comparison, and nothing in the output will tell you so. Both runs finish and both print plausible results, so the difference you report can be an artifact of your own setup.

**Why this is a standing order.** Measured in `UAMS-Web/uams-statamic` on 2026-07-31: a benchmark copied the test command byte-for-byte but left out a setup step the harness ran once up front. The run was heading for roughly double its 1300s baseline; with the step restored, it finished in 806s. The unfixed number would have been published as a regression, the opposite of the truth. An earlier bench in the same work stream had made the same omission and was labeled "gate parity."

## How to apply

1. **Open the baseline's harness and read it.** If a number came from a script or a workflow, that file is the specification. Here the CI test job is `tests` in `.github/workflows/ci.yml`, and it is not `composer test`. For each matrix cell it:
   - runs `setup-php` with a fixed extension list and `coverage: none`
   - runs `composer require "laravel/framework:13.*" "orchestra/testbench:^11.2.0" --no-update`
   - runs `composer update --prefer-lowest|--prefer-stable --prefer-dist`
   - runs `vendor/bin/pest --ci`

   The `postgres` job is a second test harness. It runs on ubuntu with PHP 8.5, `pdo_pgsql`, and a `postgres:17` service container, and runs `vendor/bin/pest --ci` with `DB_CONNECTION=pgsql` and the `DB_*` variables set in the job, then `vendor/bin/pest --ci --group=cross-connection`. The `phpstan` job runs on PHP 8.5. Setup steps live in the harness, not in the sentence someone wrote about it.

2. **Enumerate the parity checklist, and log it in the run.** For this package:
   - **PHP version and loaded extensions.** CI runs PHP 8.5 and 8.4 (PHPStan runs 8.5 only), with the listed extensions and **no coverage driver**. Local Herd PHP 8.4.23 loads **PCOV** (enabled, with `pcov.directory` resolved to `src`) plus extensions CI does not have. Compare `php -v` and `php -m` on both sides.
   - **Resolved dependency versions.** No `composer.lock` is committed. CI resolves dependencies on the day it runs, at `prefer-lowest` or `prefer-stable`, while your local `vendor/` reflects whatever your gitignored lock resolved at your last update. Compare CI's `List Installed Dependencies` step (`composer show -D`, direct dependencies only) with a local `composer show -D`. Transitive versions need a full `composer show`.
   - **Laravel and Testbench.** CI pins `laravel/framework` 13.* with Testbench `^11.2.0`. A local install resolved Laravel 13.32.0 and Testbench 11.2.0 as of 2026-09-17.
   - **OS.** CI runs ubuntu and windows; local is macOS.
   - **Database.** The `tests` matrix and a plain local `composer test` run on Testbench's in-memory SQLite `testing` connection. The `postgres` job runs on the `postgres:17` image, a floating tag, and it alone runs the `cross-connection` group. A result from one database is not a baseline for the other.
   - **Pest flags and environment.** Read in Pest 5.2.1: under `--ci`, `->only()` no longer narrows the run, and `skipOnCI()` and `skipLocally()` flip on either `--ci` or the mere presence of `CI`, `GITHUB_ACTIONS` (both set by Actions), or 17 other CI variables. `phpunit.xml.dist` sets `executionOrder="random"`. The summary prints `Random Order Seed:`, and **`--order-by random --random-order-seed=<n>` repeats that order. Both flags, always.** `--random-order-seed` alone is refused with `Test Runner Triggered PHPUnit Warning (--random-order-seed is only used when execution order is "random" …)`, because PHPUnit reads the ordering from the *command line* rather than from the config file it already loaded. `failOnWarning`, which `phpunit.xml.dist` sets here, then makes the run **exit 1 while every test passes** — measured on PHPUnit 13.3.4, four seeds, `Tests: 692 passed` and `rc=1`, with nothing in the summary or in `build/report.junit.xml` naming a cause. Found in `robot-council/core` while reproducing an ordering-dependent flake for `robot-council/core#98`, which is the one job this flag exists for.
   - **The same commit, and the same bytes under `vendor/`** (step 3).
   - **A quiet machine**, checked per [`long-running-commands`](long-running-commands.md).

   Print each parity step's exit code into the run log. A parity step that silently failed looks the same as one that was never written.

   **A suite's own setup is part of the harness.** `tests/TestCase.php` and `tests/Pest.php` decide the environment a test runs in. Before profiling a code path through a test, read what they switch on or off, and name that environment alongside the number.

3. **State the parity basis wherever you publish the number**, and **hold the code constant, not its label.** `vendor/` is gitignored, so "same commit" does not cover it, and a version string does not identify bytes. Measured in `UAMS-Web/uams-statamic` on 2026-09-05: two worktrees held one vendored package at the same version and the same source reference, yet with different file contents. So run `shasum -a 256 <the vendor file you call into>` on both sides. The parity step that fixes drift is `composer reinstall <package>`, not `composer install`, which treats the version as satisfied and leaves the drifted files in place. The same applies to anything that can produce two contents under one tag. A matching label fails in the reassuring direction, because it tells the reader to stop checking.

4. **Confirm the workload is genuinely identical.** Compare the result line, not just the duration. The **test count** must match exactly, and the **skipped count** too, because OS- and CI-conditional skips move it. The **assertion count** backs them up: chase a delta of tens, and note a delta of ones. Also confirm the baseline's tests have not moved: `git log <base>..<compared> -- tests/` should be empty.

5. **Know which flags change the workload rather than observe it.**
   - `--coverage` (`composer test-coverage`) turns on collection through PCOV or Xdebug, and CI runs with no driver at all.
   - `--mutate` refuses to run without a coverage driver, adds a coverage run, then re-runs tests for each mutant (read in `pestphp/pest-plugin-mutate` v5.0.2).
   - `--parallel` changes the process model.

   Never quote any of their wall times as a plain run's time. Coverage driver setup is [`pcov-setup`](../skills/pcov-setup/SKILL.md).

6. **Distrust a harness's own verdict field; re-derive the verdict from the raw output.** Pest's summary lines begin with ANSI escape sequences, so a line-anchored `grep '^\s*Tests:'` misses them. A harness built that way once marked ten clean runs `DEVIATION`. Strip ANSI before parsing, and when a verdict disagrees with the raw log, believe the log.

7. **Prefer more parity over more samples.** Repeat runs bound *variance*; they do nothing about *systematic* error, which is the failure this rule addresses. Spend the time on a verified checklist first.

## The DRY line

This file owns **making a measurement comparable**, including holding the code constant rather than its label. [`filing-defects-across-repos`](filing-defects-across-repos.md) applies the revision-citing half to a report that crosses a repository boundary and points here for the evidence. [`long-running-commands`](long-running-commands.md) owns *bounding* a run and not trampling a concurrent one; this rule owns whether the number the run produces means anything. [`sync-pr-branch`](sync-pr-branch.md) owns why an unchanged branch can resolve different dependencies from one day to the next.
