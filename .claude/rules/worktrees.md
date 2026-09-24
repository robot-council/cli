# Rule — work from stable, pre-bootstrapped worktree slots, never the primary checkout

Branch work happens in one of **two long-lived worktrees, called slots**. Each is bootstrapped once and kept current. Do not create a worktree for a ticket and throw it away afterward. The primary checkout, the main clone, is the reference tree and is not a work surface. Its absolute path differs per machine, so this rule never names one.

## Why slots rather than a worktree per ticket

Two sessions on one working copy collide by construction. A branch checkout in one swaps the files under the other. A `composer install` in one swaps the binaries under the other's run. One session's `git stash` gets popped by the other, and a stray file from one ends up in the other's commit. Both write the same `build/phpstan/`, `.phpunit.cache/`, and `build/report.junit.xml`. Nothing reports the collision; the other session's next result just describes a tree it did not set up. So work is isolated per tree.

The unit is a long-lived slot rather than a fresh worktree because a fresh one is not free, even where bootstrapping is little more than `composer install`:

- **It re-opens every bootstrap failure mode this file documents**, and those are the expensive part. A `vendor/` symlinked from another tree silently loads the donor's code and `tests/Pest.php`. A directory name starting with a digit kills every test before it runs. Each detour costs far more than the install it was trying to skip.
- **It changes the session's working-directory string**, which is part of the cached prompt prefix, so a never-before-seen path starts every session cold. A fixed set of paths lets that prefix recur. This is secondary: the cache TTL is an hour, so it pays within a working day, not across days.

A slot is bootstrapped once, so a solo session has no cost reason to stay in the primary either.

## The slots

Three trees, all **siblings** of each other:

| Tree | Path (relative to the primary) | Role |
| --- | --- | --- |
| Primary | *the main clone itself* | Reference checkout. **Holds the `main` branch**, current with `origin/main`. Not a work surface. |
| Slot A | `../<primary>-a` | Ticket work. Take this one first. |
| Slot B | `../<primary>-b` | A second concurrent session, or a second ticket. |

There is no local-CI slot. CI is the single GitHub Actions workflow, and nothing local checks branches in and out of a tree of its own.

### Per repository

This file is shared, byte for byte, by `robot-council/core` and `robot-council/cli`. Everything that differs between them lives in this table, and the rest of the file refers to it rather than naming either repository's values.

| | `robot-council/core` | `robot-council/cli` |
| --- | --- | --- |
| `<primary>`, the primary's directory name | `robot-council` | `robot-council-cli` |
| Slots | `../robot-council-a`, `../robot-council-b` | `../robot-council-cli-a`, `../robot-council-cli-b` |
| `composer.lock` | **gitignored**: copy the primary's into the slot before `composer install` | committed: `composer install` alone |
| npm | `npm ci` in every slot, whatever the ticket touches | none |
| First-party namespace in `vendor/composer/autoload_static.php` | `RobotCouncil\` | `App\` |
| Smoke test after provisioning | `npm run check && vendor/bin/pest --list-tests >/dev/null` exits `0` | `./robot-council about` exits `0` |
| `delete_branch_on_merge` (read 2026-09-24) | `false` | `false` |

**The last row no longer differs, and is kept because the rule below turns on it.** It was `true` on `core` until `robot-council/core#288` changed it on 2026-09-24; the pull requests merged before then still have dead file links, which is the cost this table was recording.

**They are siblings, not nested under `.claude/worktrees/`, for three reasons.** They sit outside the repository, so no ignore rule has to hold for them. A `grep -r` from the primary cannot descend into them. And a process's working directory attributes it to one tree: the separator-anchored allow-list in [`long-running-commands`](long-running-commands.md) claims every nested tree's processes from the primary, and needs an explicit exclusion to stop doing so, while a sibling never matches.

**But the primary's path is a string prefix of both slots' paths**, and `core`'s is a prefix of every sibling whose name starts with `robot-council`, cli's trees included, so the anchor is load-bearing. Measured 2026-09-24 by feeding each path to both forms of the `case`, with the primary's own path as the control:

| `MY_TREE` | Path tested | `"$MY_TREE"\|"$MY_TREE"/*)` | `"$MY_TREE"*)` |
| --- | --- | --- | --- |
| `robot-council-cli` | `robot-council-cli` | claimed | claimed |
| `robot-council-cli` | `robot-council-cli-a`, `-b` | skipped | **claimed** |
| `robot-council` | `robot-council` | claimed | claimed |
| `robot-council` | `robot-council-a`, `robot-council-cli`, `robot-council-cli-a`, `robot-council-app` | skipped | **claimed** |

A casually written bare-prefix match claims other sessions' processes, in this repository's slots and, from `core`, in other repositories entirely. The failure is killing someone else's run. Anchor on the separator, always.

**No directory or file name under `tests/` may start with a digit.** Pest 5.2.1 builds each test file's class name from its path relative to the project root (`TestCaseFactory::evaluate()`): it prefixes `P\`, strips every character that is not a letter or digit, and turns separators into namespace separators. A segment that starts with a digit is not a valid PHP identifier, so the file dies with `InvalidTestClassName` (`would create the namespace [P\Tests\9999Probe]`, or `would create the class […]` for a file) before any test runs. Control, calling that `evaluate()` directly: `tests/9999Probe/FooTest.php` is rejected, and `tests/t9999Probe/FooTest.php` passes. Pest takes the project root from where `vendor/` really lives, so the tree's own directory name is not in that relative path, and a worktree named `123-fix` is fine as long as `vendor/` belongs to the tree (see provisioning).

## Which tree does the work

- **All branch work goes in a slot.** Claim one, work in it, release it. This holds for a solo session as much as a concurrent one: a session that never touches the primary cannot collide with one that does.
- **The primary holds `main`, and stays there.** It is the tree to diff against. Leaving it on a ticket branch also destroys the oldest drift signal there is: "the primary is on a branch I did not set" means nothing if you routinely put it there yourself.

**Detected churn still means isolate first.** If a slot moves under you (the branch changed, untracked files you did not create appear, `git worktree list` shows it on a branch you did not set), treat it as a live second session on that slot. Take the other slot, or an ephemeral worktree if both are held.

## Claiming and releasing a slot

There is no lock file, and there does not need to be one: **the slot's own branch is the occupancy signal.**

```bash
git worktree list
```

**A slot at rest is detached at `origin/main`, not on `main`.** Git allows a branch to be checked out in exactly one worktree, so `main` can live only in the primary; `git worktree add` refuses a second (git 2.39.5 says `fatal: 'main' is already checked out at …`; newer git words it differently). Detaching sidesteps that and costs nothing, because a slot at rest is not a place work happens.

So a slot showing `(detached HEAD)` with a clean tree is **free**, and a slot on a branch is **held**, either by you earlier or by another session right now. Take **A** first and **B** second. If both are held and you hold neither, there are two live sessions. Do not evict one; take an ephemeral worktree. **A rebase or bisect in progress also shows `(detached HEAD)`**, so a detached slot with a `rebase-merge/`, `rebase-apply/`, or `BISECT_LOG` under `git -C "$SLOT" rev-parse --git-dir` is held, not free.

In the commands below, `$SLOT` is the slot's **absolute** path and `$PRIMARY` the primary's. A relative `../<primary>-a` resolves against whatever the cwd happens to be, and the cwd is not reliable (see the hazards section). Each block is **one fail-closed chain**, so a failed guard stops the command that follows it rather than printing a warning above it.

**The lock step**, run inside a slot before every `composer install` there, is the same in both repositories:

```bash
{ git ls-files --error-unmatch composer.lock >/dev/null 2>&1 || cp "$PRIMARY/composer.lock" composer.lock; }
```

In `cli` the lock is tracked, so it does nothing. In `core` it is untracked, so the slot takes the primary's lock, and a primary with no lock makes the `cp` fail and stops the chain rather than letting `composer install` resolve fresh. It is for slots only: the primary is the tree whose lock the slots copy.

**Claim a slot by branching from `origin/main`**, never from whatever the slot happens to be sitting on:

```bash
cd "$SLOT" &&
  test -z "$(git branch --show-current)" &&   # detached, i.e. not held
  test -z "$(git status --porcelain)" &&      # nothing a previous ticket left behind
  git fetch origin &&
  git switch --no-track -c <branch> origin/main &&
  { git ls-files --error-unmatch composer.lock >/dev/null 2>&1 || cp "$PRIMARY/composer.lock" composer.lock; } &&
  composer install &&                         # the refresh gate below
  { [ ! -e package-lock.json ] || npm ci; }   # core only
```

**`--no-track` is load-bearing.** Without it the new branch tracks `origin/main` (the default `branch.autoSetupMerge`). Under the default `push.default=simple`, a bare `git push` then refuses because the names differ, a bare `git pull` merges `main`, and `git status` reports the branch against `main`. Publish with `git push -u origin <branch>`.

**Releasing a slot is a real step, not a formality.** When a ticket ships, return the slot to rest, naming the branch you are releasing:

```bash
cd "$SLOT" &&
  test "$(git branch --show-current)" = "<branch>" &&   # the branch you are releasing, not a stranger's
  test -z "$(git status --porcelain)" &&
  git fetch origin &&
  git switch --detach origin/main
```

**Check the branch name before detaching, not only the clean tree.** A dirty tree is the case everyone guards against, because detaching would strand the work. A clean tree on a branch is the case nobody guards against, and releasing it *takes a tree somebody may be working in*. It is not an edge state: a ticket sits clean on its branch after every commit, and for the whole stretch between the last commit and the pull request. Nothing is lost when it happens (`switch --detach` leaves the branch intact). What is taken is the holder's `HEAD`, and the tell is that their *next* commit lands detached, well after the cause. This was paid for in `UAMS-Web/uams-statamic#2273`.

Three things go wrong when the release is skipped, and all three are silent:

- **The next ticket branches off the last ticket's tip** instead of current `main`, so its pull request carries commits that belong to another ticket. This is the largest new hazard the slot model introduces, and it has no loud failure: everything builds, and the branch is just wrong.
- **Untracked scratch from the previous ticket rides into the next commit.** Check before you branch, not after you commit.
- **`git status` is blind to empty directories**, so litter left by a run killed mid-write does not show up there at all. Look for it directly when a slot has hosted an interrupted run.

**A slot also accumulates what a throwaway tree never lived long enough to carry.** `build/` (PHPStan's `build/phpstan/`, Rector's `build/rector/`, `build/report.junit.xml`) and `.phpunit.cache/` are gitignored, so a clean `git status` says nothing about them, and they survive every release and every branch switch. Each tool's cache is meant to invalidate itself, but a cache that keys on less than everything it depends on replays stale results, and nothing in the output says so. So when a result disagrees with the code in front of you, suspect the carried-over cache before the code. `rm -rf build .phpunit.cache` inside the slot costs one cold run, and the next session inherits a slot that has not seen a stranger's tickets.

### A slot held by nobody

"Held" means a branch is checked out, not that a session is alive. A session that dies mid-ticket leaves its slot on a branch indefinitely, and the branch name says what was being worked on but not by whom. The slot then looks claimed forever. Before treating a held slot as abandoned, gather evidence that someone is still working in it:

```bash
git -C "$SLOT" log -1 --format='%h %cr %s'     # last commit, and how long ago
git -C "$SLOT" status --porcelain              # uncommitted work in flight
gh api "repos/robot-council/<core|cli>/pulls?state=all&head=robot-council:$(git -C "$SLOT" branch --show-current)" \
  --jq '.[] | "#\(.number) \(.state) \(.updated_at)"'
```

The pull-request lookup is REST rather than `gh pr list`, which is GraphQL-backed, per [`github-api-budget`](github-api-budget.md). It is only meaningful when the slot is on a branch; an empty result on a detached slot says nothing.

Then run the separator-anchored process sweep from [`long-running-commands`](long-running-commands.md) with that slot as `MY_TREE`. None of this proves the slot is abandoned: a session can be alive and idle, thinking rather than running anything. So a held slot you did not claim is **never reclaimed on inference.** Take the other slot or an ephemeral worktree, and put what you found in front of the user, who knows which sessions are open. If the user frees it, release it as above; a committed branch survives the detach, and uncommitted work is copied aside or committed as WIP on its branch first.

**Never delete the remote branch.** Pull-request bodies link files by absolute head-branch URL (`/blob/<branch>/<path>`, per [`writing-pull-requests`](../skills/writing-pull-requests/SKILL.md)), so a deleted head branch breaks every file link in its own pull request. So `delete_branch_on_merge` should be `false` and merges carry no `--delete-branch`. It is `false` in **both** repositories (the per-repository table), so nothing deletes a head branch at merge in either. It was `true` on `core` until `robot-council/core#288` changed it on 2026-09-24, and what that cost is measured rather than asserted: `robot-council/core#286`, `#280` and `#277` each link files by their own head-branch URL, every one of those branches is gone from the remote, and every one of those links now answers `No commit found for the ref` while the same file on `main` resolves. Those stay broken. The first branch merged after the change, `port-worktrees-rule`, is still on the remote. The local branch may be deleted once its pull request has merged. The slot itself is never torn down.

**`git branch --merged` cannot confirm that a squash-merged branch landed.** A squash merge writes a new commit on `main`, so the branch's own commits are never ancestors of it, and `--merged` omits the branch even when every byte of it is on `main` (observed in a `core` session on 2026-09-24, reading `0` for such a branch). Confirm by content instead: the files the branch touched must match `origin/main`.

```bash
base=$(git merge-base origin/main <branch>) &&
  git diff --quiet <branch> origin/main -- $(git diff --name-only "$base" <branch>) &&
  echo "landed"
```

A non-empty diff does not prove the branch did *not* land, since `main` may have changed those files since. Read the diff before deciding.

## Keeping a slot current: the refresh gate

This is the risk the slot model **introduces**, and it is worse than the one it removes. A fresh worktree fails *loudly*: no `vendor/`, hard error, you notice. A stale slot fails *silently*: the tests run, they pass, and they pass against the wrong dependency versions. In `UAMS-Web/uams-statamic`, a checkout's `vendor/` sat at Pest 4.7.5 while `composer.lock` pinned 5.0.1, and nothing said so.

**So whenever a slot's base moves, run the lock step and `composer install` before any check whose result you intend to trust.** The base moves when you claim the slot from a newer `origin/main`, or when you sync a branch per [`sync-pr-branch`](sync-pr-branch.md). `composer install` reconciles `vendor/` to exactly the lock, and it prints `Nothing to install, update or remove` when the slot was already right. There is no reason to reason about whether it is needed; just run it. In `core`, `npm ci` follows it every time, for the same reason and whatever the ticket touches: a slot is set up completely rather than for the ticket in hand, so the next ticket never inherits a half-built tree. `npm ci` rather than `npm install`, because `install` reconciles against `package.json` and can leave `package-lock.json` modified, while `ci` installs exactly the lock. The primary needs the same gate whenever it pulls `main`.

**Which lock it reconciles to is where the two repositories differ.** In `cli` the lock is committed, so two trees on one commit resolve to identical `vendor/` contents. In `core` the lock is gitignored, so each slot keeps whatever it resolved on the day it was installed, two slots installed on different days silently hold different dependency versions, and "same commit" does not cover it. That is what the lock step is for: in `core`, the primary is the one tree whose lock moves (by `composer update`), and every slot copies it. A branch that changes `composer.json` is the exception: its slot runs `composer update` for the packages it changed, and its lock is no longer the primary's until the branch merges and the primary updates. Comparing two trees' results needs the same lock on both, per [`measurement-parity`](measurement-parity.md).

**`composer install` compares versions, not bytes.** A `vendor/` file edited in place, for instance while debugging a dependency, survives it. The fix is `composer reinstall <package>`, and the check is `shasum -a 256` against a known-good tree, per [`measurement-parity`](measurement-parity.md).

**Read staleness directly:** `git -C <tree> rev-list --count HEAD..origin/main` after a fetch. It does not depend on the tree carrying the code that does the inspecting. That property matters: a guard that ships inside the tree it guards cannot report on states older than itself. It is silent exactly where the tree is most out of date, and that silence looks like health.

## Provisioning a slot (one time, and once only)

All four slots were provisioned on macOS on 2026-09-24, and each passed its smoke test from the per-repository table. To provision or re-provision one:

```bash
cd "$PRIMARY" &&
  git fetch origin &&
  git worktree add --detach "$SLOT" origin/main &&            # at rest: `main` belongs to the primary
  cd "$SLOT" &&
  { git ls-files --error-unmatch composer.lock >/dev/null 2>&1 || cp "$PRIMARY/composer.lock" composer.lock; } &&
  composer install &&
  { [ ! -e package-lock.json ] || npm ci; }                     # core only
```

Then run the smoke test from the table. Everything `composer install` writes is gitignored, so none of it can ride a commit; a clean `git status` afterward confirms it. There is no `.env` to copy.

**Never symlink `vendor/` from another tree to skip `composer install`.** PHP resolves `__DIR__` through the link. Composer's `autoload_static.php` builds the first-party paths (the namespace in the table) from `__DIR__`, so the first-party classes and `tests/TestCase.php` load from the **donor's** tree, and Pest takes the donor as its root and boots the donor's `tests/Pest.php` (read from source, not executed here). Your test files run against somebody else's code and bootstrap. It cuts both ways: a regression passes, and a fix appears not to work. The telltale sign is a change you just made having no effect.

## Ephemeral worktrees

Slots cover ordinary work. A throwaway worktree is still right in exactly three cases:

- **Both slots are held** and you need a third tree now.
- **Genuinely file-disjoint parallel fan-out**, where several tickets are built at once rather than in sequence.
- **Subagents**: the `Agent` tool's `isolation: "worktree"`, a different mechanism aimed at a different problem, unaffected by any of this.

For those, the `EnterWorktree` tool creates a tree under `.claude/worktrees/<name>/` on a fresh branch and moves the session into it. The tool acts only on a user request or a project instruction, and this rule is that instruction for the three cases above.

- **`.gitignore` ignores the `.claude/worktrees` directory, and the entry must stay.** Without it, every nested tree sits untracked inside the primary: measured in a scratch repository on git 2.39.5 (2026-09-17), `git status` in the primary listed `?? .claude/worktrees/<name>/`, and `git add -A` staged the tree as an embedded repository (a gitlink) with only a warning. The ignore does not blind the tools inside a tree: measured 2026-09-17 with Pint 1.32.1, `pint --test` run from a nested worktree caught a planted violation both with and without the entry.
- **Nesting has two effects the ignore does not remove.** `grep -r` from the primary descends into every nested tree (PHPStan and PHPUnit name explicit paths, and Pint's finder skips dot-directories). And a process can no longer be attributed to the primary by working directory alone, because the primary's separator-anchored prefix contains every nested tree; the sweep in [`long-running-commands`](long-running-commands.md) excludes them explicitly.
- **Prefer `EnterWorktree` over `git worktree add` plus entering it.** Entering a tree the harness did not create is recorded as `"enteredExisting": true` with no `originalBranch` or `originalHeadCommit`, so the session shows no worktree marker in its history, and its transcript is filed under the worktree's project slug rather than the repository's. Wanting a specific branch name is not a reason to take the manual path: create with `EnterWorktree`, then `git switch -c <branch>` inside it. Reserve the manual path for a **pre-existing** branch you must not lose, since `ExitWorktree`'s `remove` deletes the tree **and its branch**: run `git worktree add .claude/worktrees/<name> <branch>` and enter it with `EnterWorktree`'s `path`. The slots are entered the same way, and `ExitWorktree` will not remove a tree entered like that.

## Committing and pushing needs nothing extra

Neither repository has git hooks: in both, `core.hooksPath` is unset and the hooks directory holds only git's samples (read 2026-09-24). A commit or push therefore runs no tests, Pint, or PHPStan, and even an un-bootstrapped tree can branch, commit, and push. The gate is `ci-passed` on the pull request.

## File-tool and shell-cwd hazards

These matter **more** with long-lived slots, not less: every slot is always present, always on some branch, and always a plausible destination for a misdirected write.

- **`EnterWorktree` switches the shell cwd, but `Read`, `Edit`, and `Write` act on the absolute path you pass.** Keep passing primary-checkout paths and your edits land in the primary. Pass the slot's absolute path, and `Read` that copy before editing it; read state is tracked per absolute path.
- **The Bash cwd can silently revert between calls**, sometimes with a `Shell cwd was reset to …` notice and sometimes with none. A backgrounded command inherits whatever the cwd is at launch. The result is a false green from the wrong tree, or an in-place shell edit (`sed -i`, `cat >`) that rewrites the other tree's copy of the file while the one you meant to edit stays untouched and green. Put an explicit `cd` in every command, and make long runs show where they ran:

  ```bash
  cd "$SLOT" && echo "CWD=$(pwd) HEAD=$(git rev-parse --short HEAD)" && <the real command>
  ```

  The guard matters more with slots than with per-ticket trees, because slots do not differ by name. `<primary>-a` and `<primary>-b` look alike, and the stale cwd is as likely to point at the other slot as at the primary. So the right question is "which tree did it land in?", not "did it land on `main`?". Prefer the file tools with absolute paths for edits. If work lands in the wrong tree, `cp` the files to where they belong, then restore the wrong tree's copy (see the next item for when that is safe).
- **`git checkout -- <file>` discards all uncommitted work on that file**, not just your last change. Use it only where the file should match `HEAD`. To undo a temporary edit on a file that also holds uncommitted work you want, such as reverting a fix for a negative control, apply the inverse edit or `cp` the file aside first. If you lose work anyway, rebuild it from the session transcript's `Read` and `Edit` results plus `HEAD`, then re-verify before trusting it.
- **The stash stack belongs to the repository, not the worktree.** Verified on git 2.39.5: a stash pushed in a linked worktree shows up as `stash@{0}` in the primary. Two mistakes chain into losing someone else's work. First, `git stash push -- <paths>` naming any untracked path fails (`error: pathspec … did not match any file(s) known to git`, exit 1) and stashes **nothing**. Then the paired bare `pop` applies whatever entry another session pushed. Prefer a WIP commit, or `cp` the file aside. If you must stash, run `git stash push -u -m "<unique-tag>"`, take the SHA from `git stash list --format='%H %gs'`, and `git stash apply <sha>`. Never use a bare `pop`. **Recovery**, because a popped stash commit is unreachable, not gone:

  ```bash
  git fsck --unreachable --no-progress | awk '/commit/ {print $3}' |
    while read c; do git log -1 --format="$c %s" "$c"; done | grep -E ' (On|WIP on) '
  git stash store -m "<the original message>" <sha>
  ```

  Then revert the popped files out of your tree and confirm with `git stash list` that the stack is back to its old depth.
- **The stash is one instance of a shared read-modify-write surface.** That is anything two actors can read, change, and write back with no locking, so the last writer wins and the loser is never told.

  | Surface | Shared between | Safe operation | Destructive operation |
  | --- | --- | --- | --- |
  | git stash stack | every worktree of one repository | a WIP commit, or `cp` aside | `stash push` / bare `pop` |
  | project memory | every session on a machine | add a new file, one fact per file | rewriting a shared file whole |
  | GitHub issue or PR body | everyone | post a comment | edit the body |

  **Slots do not partition project memory.** Its directory is keyed to the primary checkout's path (observed in `UAMS-Web/uams-statamic`), so every session writes the same files whichever slot it works in. Isolation that stops at the repository boundary still reads as isolation, which is what makes it dangerous.

  **Appending cannot clobber; rewriting can.** Checking that your anchor is still there, or comparing a base hash, only shows that your edit lands where you intended and that nothing changed between your read and your write. It cannot see a concurrent edit elsewhere in the same artifact, or one made before your read. Delete only what you wrote, and first confirm it is still what you wrote.

## Rebuilding a slot, and removing an ephemeral worktree

A slot is normally never removed; that is the point. Rebuild one only when its bootstrap is beyond refreshing, such as a corrupted `vendor/`. For a tree `EnterWorktree` created, use `ExitWorktree`. Otherwise:

1. **Move every shell's cwd out of the tree and stop anything started in it.** On Windows, a shell whose working directory sits inside the tree holds a lock, and `rm -rf` then fails with `Device or resource busy`.
2. **Look before deleting**, and copy any untracked scratch worth keeping to the scratchpad.
3. **Run `git worktree remove --force <path>`.** Without `--force` it refuses on modified or untracked files.
4. **If something outside git blocks it**, such as an OS handle, run `rm -rf <path>` and then `git worktree prune`. If the directory was deleted outside git, the branch stays pinned to the dead tree (`git worktree list` shows it `prunable`, and checking the branch out elsewhere fails) until that same `git worktree prune`.

The branch survives either way (verified on git 2.39.5). PHPStan's cache goes with the tree: `tmpDir: build/phpstan` resolves against the config file's directory, so its container and result caches live under each tree's own `build/phpstan/` and leave nothing machine-global pointing at a deleted tree.

## The DRY line

This file owns **the worktree lifecycle**: the slot model, claiming and releasing, the refresh gate, and removal. The `EnterWorktree` and `ExitWorktree` mechanics live in those tools' own descriptions and are not restated here. A slot changes *where* you branch, not *how* you ship, which is [`closing-a-ticket`](closing-a-ticket.md) and [`pre-merge-check`](pre-merge-check.md). Bringing a branch current is [`sync-pr-branch`](sync-pr-branch.md); this rule owns refreshing the tree that branch sits in. Not trampling a concurrent run once isolated is [`long-running-commands`](long-running-commands.md). Keeping two trees' results comparable is [`measurement-parity`](measurement-parity.md).
