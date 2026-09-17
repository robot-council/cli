# Rule — bound every long-running command, and resolve what you kill

A command that may run for minutes gets an **explicit bound inside the command itself**, and any process you are about to kill gets **identified first**. Neither the Bash tool's `timeout` parameter nor `run_in_background` stops work. **They detach it.** When the tool timeout fires, you get `exit 143` and a clean-looking prompt while the PHP process you started keeps running.

**Why this is a standing order.** A detached run does not sit still. It competes with every run you start next, so your next timing comes back slow and your next pass/fail may be poisoned. It also holds files the next run wants, here `build/phpstan/`, `.phpunit.cache/`, and `build/report.junit.xml`. Nothing warns you: the tool result is telling the truth about the *wrapper*, not about the work.

## How to apply

1. **Bound it in the command, with a mechanism that works on your platform.** Prefer GNU `timeout`, and fall back to the forking perl form only where it is absent:

   ```bash
   if command -v timeout >/dev/null 2>&1; then
     bound() { timeout "$@"; }                    # rc=124 on expiry
   elif command -v gtimeout >/dev/null 2>&1; then
     bound() { gtimeout "$@"; }                   # Homebrew coreutils, same contract
   else
     bound() {                                    # macOS/Linux only
       local t=$1; shift
       perl -e 'my $t = shift; my $p = fork // die "fork: $!";
                if (!$p) { setpgrp(0, 0); exec @ARGV; die "exec: $!" }
                $SIG{ALRM} = sub { kill "TERM", -$p; sleep 2; kill "KILL", -$p; exit 124 };
                alarm $t; waitpid($p, 0); my $rc = $?; alarm 0;
                exit($rc & 127 ? 128 + ($rc & 127) : $rc >> 8);' "$t" "$@"
     }
   fi
   bound 600 vendor/bin/pest --parallel
   ```

   - **macOS ships neither `timeout` nor `gtimeout`.** Verified on macOS 26.6.2 (arm64); `gtimeout` arrives only with Homebrew coreutils. The fallback is the branch that actually runs there. A bare `timeout` fails with `command not found`, and inside a pipeline that can read as exit 0.
   - **The fallback kills the process GROUP, and that is what matters.** The older `perl -e 'alarm shift; exec @ARGV'` signals only its direct child. Measured in `UAMS-Web/uams-statamic` on macOS 26.4: that form killed a `pest --parallel` parent on schedule and left a `pest/bin/worker.php` running, while the forking form left none. On macOS 26.6.2, the forking form bounding `sh -c 'sleep 30 & sleep 30; wait'` returned 124 and left no `sleep` behind.
   - **Expect `t + 2` seconds, not `t`.** The handler sends `TERM`, waits 2 seconds, then sends `KILL`. That is the grace period, not drift, so do not tighten the bound to compensate.
   - **PHP turns an external `SIGALRM` into its own fatal error, so trust the exit code, not the message.** Under the old one-liner, PHP prints `Fatal error: Maximum execution time of 0 seconds exceeded`. Bare `php` exited 255 (measured on macOS 26.6.2 with PHP 8.4.23), and under Pest it has surfaced as `Pest\Exceptions\FatalException` with exit 1, which looks exactly like a test failure. The forking form exits **124**, like GNU `timeout`, and passes real exit codes through (verified: `7` arrives as `7`).
   - **Windows:** Git Bash ships GNU `timeout` at `/usr/bin/timeout`, so use that. The bare `perl alarm` one-liner silently does nothing there.

2. **Size the bound to the measured runtime, not to your patience.** A bound that fires reads exactly like a failure and invites you to debug a passing run. Measure the real runtime once, then set the bound well above it. **A bound that fires can also destroy the artifact you wanted:** Pest 5.2.1's `--update-shards` writes `tests/.pest/shards.json` only when the run passes (`src/Plugins/Shard.php`). When a run exists to produce something at the end, size its bound for the worst case.

3. **Resolve a process before you kill it.** `pgrep -f` and `pkill -f` match the full command line, and the shell issuing your command carries that command's whole text in its own argv. So `pkill -f 'vendor/bin/pest'` can match and kill the shell that runs it. List candidates with their working directory, and kill only what is yours:

   ```bash
   for pid in $(pgrep -f 'vendor/bin/pes''t'); do
     printf '%s  %s\n' "$pid" "$(lsof -a -p "$pid" -d cwd -Fn | sed -n 's/^n//p')"
   done
   ```

   The split string is the point. The shell joins `'vendor/bin/pes''t'` into `vendor/bin/pest`, but the joined pattern never appears in the searching command's argv, so the query cannot match itself. On Windows, `Get-CimInstance Win32_Process` is the instrument (MSYS `ps` undercounts), and a `-like` filter matches your own invocation the same way: list first, then `Stop-Process -Id` only the PIDs you confirmed.

4. **Never kill a process whose cwd is not yours. Allow-list your own tree; never denylist other trees.** A denylist treats every path you did not think of as "mine." Instead, resolve each candidate's cwd and act only on what sits at your tree or beneath it, **anchored on the separator** (`"$MY_TREE"|"$MY_TREE"/*`). Never use a bare prefix, because a sibling worktree `…/robot-council-2` has `…/robot-council` as a string prefix.

   **Nested worktrees defeat that anchor from the primary.** `EnterWorktree` puts trees at `.claude/worktrees/<name>/` *inside* the primary checkout ([`worktrees`](worktrees.md)), so from the primary, `"$MY_TREE"/*` also claims every concurrent session's processes. Exclude them explicitly, as the sweep in step 6 does.

   **`MY_TREE` must come from something the invocation carries**: an explicit argument, an environment variable set at launch, or a `cd` inside the same command. Never use `$(pwd)`. The Bash session cwd can revert between calls, so an ambient-cwd guard is right on one invocation and wrong on the next. That failure **fails open**: no match, "clear", exit 0.

   **Cwd cannot tell apart processes that share a directory.** Two sessions in one checkout, or background processes launched without their own `cd`, have byte-identical working directories, and nothing else on their command lines distinguishes them. **So put an identifying token in the argv of anything that outlives the tool call that started it.** The token can be inert, as long as it is placed so the shell cannot `exec` it away:

   ```bash
   bash -c '<the real command>; : owner=<token>'   # the trailing no-op keeps this shell alive as the parent
   ```

   `pgrep -f` on the token (split-quoted as in step 3) then finds the wrapper, and `pgrep -P <pid>` finds the work beneath it. The token goes last because a shell may `exec` the final command of a `-c` string and replace itself. Both orders kept the wrapper on bash 3.2, but the trailing no-op does not depend on the version. **An unattributable process burdens everyone who sweeps**, because "not yours by default" means no one can ever clear it.

   **`pgrep -f` also matches wrapper shells, so a matched line is not a process you own.** The harness shell that launched a command carries the same text as the command, and so does a `bash -c` wrapper. One job can match as two or more lines, and how many varies with how many wrappers are alive at that moment. Kill by resolved PID, and **count attributed PIDs, never matched lines.**

5. **Kill the parent loop first, or the run restarts itself.** Killing the child of a loop ends only that iteration, and the parent launches the next one. Kill parent scripts first, then any surviving children, then **re-list and confirm zero**. One sweep is not proof, because a `pest --parallel` parent starts replacement workers while you are killing them. The harness's `TaskStop` stops the task it tracks, not necessarily a detached tree that task spawned. In `UAMS-Web/uams-statamic`, stopping a looping script's task let its next iteration start. Confirming zero also assumes you are the only actor: a process that reappears under a new PID after you killed it raises the question of who else is acting. Every pattern here matches a tool name, so a bare orphaned `sleep` escapes all of them. An orphan of `bash -c 'sleep N; <command>'` whose shell is gone will run nothing after the sleep, but repeated orphans at about that interval mean the thing spawning them is still alive.

6. **Confirm the run actually ended before you measure anything, and make the script confirm it.** After a bound fires, or whenever a command returns `143`, check for strays before taking a timing or a pass/fail. Put the check inside any script whose output you intend to trust, and make it **abort** rather than print a poisoned result:

   ```bash
   # macOS: cwd via lsof. MY_TREE is carried, absolute, resolved (lsof prints /private/tmp,
   # not /tmp), and has no trailing slash.
   MY_TREE=${1:?pass the tree this run belongs to}
   strays=0
   for pid in $(pgrep -f 'bin/pes''t|phpsta''n' | grep -vx "$$"); do
     cwd=$(lsof -a -p "$pid" -d cwd -Fn 2>/dev/null | sed -n 's/^n//p')
     case "$cwd" in
       "$MY_TREE"/.claude/worktrees/*) ;;          # another session's nested tree
       "$MY_TREE"|"$MY_TREE"/*) echo "stray pid=$pid :: $cwd"; strays=$((strays + 1)) ;;
     esac
   done
   [ "$strays" -eq 0 ] || { echo "ABORTED: $strays competing run(s) in this tree"; exit 3; }
   ```

   This sweep was exercised on macOS 26.6.2 (2026-09-17) against planted processes. From the primary it counted the primary's process and skipped the nested tree's. From the nested tree it counted its own. An unrelated tree returned 0. On Linux, read `readlink /proc/<pid>/cwd` instead of `lsof`, with the same contract. An abort caused by an over-count costs a re-run, not a corrupted result. That is the safe direction, and it is the reason the script counts attributed PIDs.

## Validate the sweep before you trust its silence

**An empty result from a blind sweep is byte-identical to an empty result from a quiet machine.** Before reading "nothing found" as an absence, **show the sweep finding a process you know exists.** This is the process-sweep case of [`an-empty-result-is-not-evidence`](an-empty-result-is-not-evidence.md), and these six causes are its fullest treatment. Only the first is a selection error, and the last two are not detection failures at all:

| Cause | Measured | Why it returns clean |
| --- | --- | --- |
| `ps` without `-A`/`-a`/`-e` (macOS) | 10 of 662 processes (macOS 26.6.2) | Lists only the terminal's own processes. `-ww` sets **width**, not selection. |
| `Win32_Process` `CommandLine` null (Windows) | 151 of 712 unreadable | A permissions boundary that no rewrite of the filter reaches. |
| Filtering on the process name (Windows) | 4 against 16 | A waiter is `sleep.exe` or `bash.exe`, so a `php`/`node` name filter cannot match it. |
| `grep -v grep` (all platforms) | 4 matches, then 0 | Drops every process whose command line contains `grep`, which watchers and waiters often do. Use the split-string idiom from step 3 instead. |
| A GNU flag on BSD `pgrep` (macOS) | published `0`, actual `3` | `pgrep -c` does not exist on macOS (exit 2, verified on 26.6.2). `pgrep -fc … 2>/dev/null \|\| echo 0` swallows the usage error and prints a reassuring `0`. |
| The predicate matched the wrong surface | a live foreign process in the output | A shell running a script carries only the script's *path*; the behavior lived inside the file. |

Rows 2 through 6 were measured in `UAMS-Web/uams-statamic` sessions.

**A passing control shows DETECTION, not COVERAGE.** On one machine at one instant, `pgrep -f` found 3 of a class and passed its positive control, while `ps -Aww … | grep` found 7. The defensible statement is "at least three, possibly seven." So the control has a second half:

- **Count what the sweep could not read**, not only what it matched.
- **Check the predicate against the surface where the behavior actually lives.**

Every error made about these counts leaned toward reassurance, and for a sweep run to keep a machine quiet, under-reporting is the worst direction to err.

## The same blindness on an outward-facing write

The same failure applies to a command that **writes somewhere other people read**, such as a `gh issue comment`, a `gh pr comment`, or an issue body edit. A misread sweep wastes your own time. A bad outward write is on the permanent record under your name before anyone notices.

| | How it fails | What detects it |
| --- | --- | --- |
| **A. Never sent** | The tool crashed. Through `\| tail -1`, the last line of a stack trace reads like ordinary tool chatter. | Read the status **before any pipe**: `cmd > out 2>&1; rc=$?` |
| **B. Sent, wrong body** | The step that should have written the body file never ran (a refused compound command, a wrong path), so an **older file at the same path** was posted. The exit code is 0, and nothing went wrong from the tool's side. | **Only a read-back** of what was posted, compared with the intended text |

**No amount of exit-code hygiene catches B.** So **write the body and send it in one command**, for example a heredoc piped into `gh pr comment N --body-file -`, so a refusal fails closed with nothing written and nothing sent. Then **read back what was actually posted** (`gh api repos/robot-council/core/issues/comments/<id> --jq .body`) and compare it with what you meant to post. The comment URL `gh` prints on success carries a server-assigned ID that a crashed run cannot fabricate. It proves a comment landed, not that its body is right.

**A read-back needs its own controls.** A fetch that quietly failed reports no mismatch, which looks exactly like a match, so pair it with a positive control. Keep two kinds of difference apart. A symmetric transformation has an inverse and is not damage. An asymmetric one, such as a character the shell ate, never comes back. A read-back that treats them alike will either dismiss real corruption or chase a rendering artifact.

## The DRY line

This file owns **bounding, attributing, and killing local processes**, and read-back of outward writes. [`worktrees`](worktrees.md) owns *isolation* between concurrent sessions; this rule owns not trampling one once isolated. [`an-empty-result-is-not-evidence`](an-empty-result-is-not-evidence.md) states the general form of the sweep-validation principle. [`measurement-parity`](measurement-parity.md) owns whether the number a run produces means anything. The mechanics of individual tools (`run_in_background`, `timeout`, Monitor, `TaskStop`) live in those tools' own descriptions and are not restated here.
