# Rule — isolate concurrent sessions in their own worktrees

**Two sessions never share one working copy.** If a session works on this repository while another is already working on it, the second session works in its own git worktree, created with the `EnterWorktree` tool. A solo session may work in the primary checkout.

## Why this is a standing order

Two sessions on one working copy collide by construction. A branch checkout in one swaps the files under the other. A `composer update` in one swaps the binaries under the other's run. A stray file from one ends up in the other's commit. Both write the same `build/phpstan/` cache, `.phpunit.cache/`, and `build/report.junit.xml`. Nothing reports the collision; the other session's next result just describes a tree it did not set up.

Isolation is cheap here: bootstrapping a tree is one `composer install`, with no submodules, no npm, and no served site. **But no `composer.lock` is committed, so a new tree resolves dependencies as of today** and can end up with different versions from the tree it sits beside. When results from two trees must be comparable, copy the other tree's (gitignored) `composer.lock` into the new tree before `composer install`, per [`measurement-parity`](measurement-parity.md).

## How to apply

1. **Create the tree with `EnterWorktree`.** It creates `.claude/worktrees/<name>/` on a new branch and moves the session into it. The branch starts from `origin/<default-branch>` under the default `worktree.baseRef: fresh`, or from local `HEAD` under `head`. The tool acts only on a user request or a project instruction, and this rule is that instruction for a concurrent session. `ExitWorktree` removes only trees `EnterWorktree` created, and its `remove` deletes the tree **and its branch**. So for a pre-existing branch you must not lose, run `git worktree add .claude/worktrees/<name> <branch>` and enter it with `EnterWorktree`'s `path`. `ExitWorktree` will not remove a tree entered that way.

2. **`.gitignore` ignores `/.claude/worktrees/`, and the entry must stay.** Without it, every tree sits untracked inside the primary checkout: measured in a scratch repository on git 2.39.5 (2026-09-17), `git status` in the primary listed `?? .claude/worktrees/<name>/`, and `git add -A` staged the tree as an embedded repository (a gitlink) with only a warning. The ignore does not blind the tools *inside* a tree: measured 2026-09-17 with Pint 1.32.1, `pint --test` run from a nested worktree caught a planted violation both with and without the entry (a real concern, because some finders skip everything an ancestor repository's `.gitignore` matches). Nesting still has two effects the ignore does not remove: `grep -r` from the primary descends into every nested tree (PHPStan and PHPUnit name explicit paths, and Pint's finder skips dot-directories), and processes can no longer be attributed by working directory alone; see [`long-running-commands`](long-running-commands.md).

3. **No directory or file name under `tests/` may start with a digit.** Pest 5.2.1 builds each test file's class name from its path relative to the project root (`TestCaseFactory::evaluate()`): it prefixes `P\`, strips every character that is not a letter or digit, and turns separators into namespace separators. A segment that starts with a digit is not a valid PHP identifier, so the file dies with `InvalidTestClassName` (`would create the namespace [P\Tests\9999Probe]`, or `would create the class […]` for a file) before any test runs. Control, calling that `evaluate()` directly: `tests/9999Probe/FooTest.php` is rejected, and `tests/t9999Probe/FooTest.php` passes. Pest takes the project root from where `vendor/` really lives, so the worktree's own directory name is not in that relative path, and a name like `123-fix` is fine as long as `vendor/` belongs to the tree (next item).

4. **Never symlink `vendor/` from another tree to skip `composer install`.** PHP resolves `__DIR__` through the link. Composer's `autoload_static.php` builds the `RobotCouncil\` paths from `__DIR__`, so the package's classes and `tests/TestCase.php` load from the **donor's** tree, and Pest takes the donor as its root and boots the donor's `tests/Pest.php`. Your test files run against somebody else's code and bootstrap (read from source, not executed). The telltale sign is a change you just made having no effect.

5. **File tools and the shell cwd do not follow each other.**
   - **`EnterWorktree` switches the shell cwd, but `Read`, `Edit`, and `Write` act on the absolute path you pass.** Keep passing primary-checkout paths and your edits land in the primary. Pass the worktree's absolute path, and `Read` that copy before editing it.
   - **The Bash cwd can silently revert between calls**, sometimes with a `Shell cwd was reset to …` notice and sometimes with none. A backgrounded command inherits whatever the cwd is at launch. The result is a false green from the wrong tree, or an in-place shell edit (`sed -i`, `cat >`) that rewrites the other tree's copy of the file. Put an explicit `cd` in every command, and make long runs show where they ran:

     ```bash
     cd <tree> && echo "CWD=$(pwd) HEAD=$(git rev-parse --short HEAD)" && <the real command>
     ```

     The right question is "which tree did it land in?", not "did it land on `main`?". If work lands in the wrong tree, `cp` the files to where they belong, then restore the wrong tree's copy (see the next item for when that is safe).

6. **`git checkout -- <file>` discards all uncommitted work on that file**, not just your last change. Use it only where the file should match `HEAD`. To undo a temporary edit on a file that also holds uncommitted work you want, such as reverting a fix for a negative control, apply the inverse edit or `cp` the file aside first. If you lose work anyway, rebuild it from the session transcript's `Read` and `Edit` results plus `HEAD`, then re-verify before trusting it.

7. **The stash stack belongs to the repository, not the worktree.** Verified on git 2.39.5: a stash pushed in a linked worktree shows up as `stash@{0}` in the primary. Two mistakes chain into losing someone else's work. First, `git stash push -- <paths>` naming any untracked path fails (`error: pathspec … did not match any file(s) known to git`, exit 1) and stashes **nothing**. Then the paired bare `pop` applies whatever entry another session pushed. Prefer a WIP commit, or `cp` the file aside. If you must stash, run `git stash push -u -m "<unique-tag>"`, take the SHA from `git stash list --format='%H %gs'`, and `git stash apply <sha>`. Never use a bare `pop`. **Recovery**, because a popped stash commit is unreachable, not gone:

   ```bash
   git fsck --unreachable --no-progress | awk '/commit/ {print $3}' |
     while read c; do git log -1 --format="$c %s" "$c"; done | grep -E ' (On|WIP on) '
   git stash store -m "<the original message>" <sha>
   ```

   Then revert the popped files out of your tree and confirm with `git stash list` that the stack is back to its old depth.

8. **The stash is one instance of a shared read-modify-write surface.** That is anything two actors can read, change, and write back with no locking, so the last writer wins and the loser is never told.

   | Surface | Shared between | Safe operation | Destructive operation |
   | --- | --- | --- | --- |
   | git stash stack | every worktree of one repository | a WIP commit, or `cp` aside | `stash push` / bare `pop` |
   | project memory | every session on a machine (observed in `UAMS-Web/uams-statamic` to be keyed to the primary checkout, so worktrees do not partition it) | add a new file, one fact per file | rewriting a shared file whole |
   | GitHub issue or PR body | everyone | post a comment | edit the body |

   **Appending cannot clobber; rewriting can.** Checking that your anchor is still there, or comparing a base hash, only shows that your edit lands where you intended and that nothing changed between your read and your write. It cannot see a concurrent edit elsewhere in the same artifact, or one made before your read. Delete only what you wrote, and first confirm it is still what you wrote.

9. **Removing a worktree.** For a tree `EnterWorktree` created, use `ExitWorktree`. Otherwise:
   - Move every shell's cwd out of the tree and stop anything started in it.
   - Look before deleting, and copy any untracked scratch worth keeping.
   - Run `git worktree remove --force <path>`. Without `--force` it refuses on modified or untracked files.
   - If the directory was deleted outside git, the branch stays pinned to the dead tree (`git worktree list` shows it `prunable`, and checking the branch out elsewhere fails) until `git worktree prune`.

   The branch survives either way (all verified on git 2.39.5). PHPStan's cache goes with the tree: `tmpDir: build/phpstan` resolves against the config file's directory, so its container and result caches live under each tree's own `build/phpstan/` and leave nothing machine-global pointing at a deleted tree.

## The DRY line

This file owns **isolation between concurrent sessions and the lifecycle of a worktree**. The `EnterWorktree` and `ExitWorktree` mechanics live in those tools' own descriptions and are not restated here. Bringing a branch current is [`sync-pr-branch`](sync-pr-branch.md); a worktree changes *where* you branch, not *how* you ship. Not trampling a concurrent run once isolated is [`long-running-commands`](long-running-commands.md). Keeping two trees' results comparable is [`measurement-parity`](measurement-parity.md).
