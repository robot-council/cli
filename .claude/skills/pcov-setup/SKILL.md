---
name: pcov-setup
description: >-
  How to install and enable the PCOV coverage driver for Pest mutation testing (`--mutate`) and
  code coverage (`composer test-coverage`) under Laravel Herd on macOS and Windows, and how to
  run and triage a mutation run once a driver is present. Activate when coverage or mutation runs
  are slow or falling back to Xdebug (`herd coverage`), when `--mutate` errors with "Mutation
  testing requires code coverage to be enabled" or reports "0 Mutations for 0 Files created",
  when every `php` on a Mac suddenly dies with `Killed: 9`, when setting up a new machine for the
  adversarial-review mutation step, or when the user asks how to get fast coverage. Covers the
  Herd static-PHP constraint, building and signing the extension, wiring it into Herd's PHP
  config, diagnosing a machine where php is killed on start, the invocation traps, and survivor
  triage.
---

# Set up PCOV (fast coverage driver) for Herd

Pest's mutation testing (`vendor/bin/pest --mutate`) and coverage (`composer test-coverage`,
which runs `vendor/bin/pest --coverage`) need a **coverage driver**. Herd ships only **Xdebug**
(`herd coverage` loads it), which collects coverage several times slower than **PCOV**, a
coverage-only driver. This skill gets PCOV loaded into Herd's PHP so both run on the fast driver
without the `herd coverage` wrapper.

The driver is a **per-machine install that does not travel via git** — each machine needs it
once. Set expectations: PCOV speeds coverage collection, but `--mutate` re-runs the covering
tests in a separate PHP process per mutant, and no driver speeds that loop up.

## The Herd constraint (why you can't just `pecl install`)

Herd's PHP is a **statically compiled build** (`php -v` prints "Built by Laravel Herd"; NTS,
arm64 on Apple Silicon). It ships **no PCOV**, has **no CLI toggle** for one, and `pecl install`
cannot target it (no `phpize` / `php-config` headers, and its `extension_dir` points at a path
that does not exist).

But Herd **loads external `.so` / `.dll` extensions by absolute path** — that is how it loads its
own Xdebug. So: **obtain a PCOV binary built for the matching PHP, then point Herd's ini at it.**

**API match is the hard requirement.** A `pcov.so` / `pcov.dll` built for the same extension API
(PHP 8.4 → `20240924`), the same thread safety (NTS), and the same architecture loads **across
patch releases**: Herd's 8.4.23 has loaded a `.so` built against Homebrew's 8.4.22. Herd's
`php -r 'echo PHP_EXTENSION_DIR, "\n";'` ends in `no-debug-non-zts-20240924`, which shows the
first two at once.

**On macOS there is a second requirement: the `.so` must carry an ad-hoc code signature.**
Skipping it does not fail the load with a warning; it can kill `php` outright (see below).

## macOS + Herd

Use a Homebrew **`php@8.4`** purely as the **build toolchain** for `pcov.so`, then load that
`.so` into **Herd's** PHP. Herd stays the default `php`.

```bash
# 1. Build toolchain (homebrew-core, keg-only). The install LINKS php into /opt/homebrew/bin
#    and shadows Herd as the default `php`, so unlink in the SAME command: a run that stops
#    here cannot leave Herd shadowed. Invoke brew's php by full path only.
brew install php@8.4 && brew unlink php@8.4

# 2. Build + install pcov against brew's php@8.4. The pcre2 headers are NOT on the default
#    include path; without CPPFLAGS the build fails with "'pcre2.h' file not found".
printf "\n" | CPPFLAGS="-I$(brew --prefix pcre2)/include" \
  /opt/homebrew/opt/php@8.4/bin/pecl install -f pcov
#    -> installs /opt/homebrew/lib/php/pecl/20240924/pcov.so

# 3. Stage the .so in Herd's config dir (decoupled from the brew keg), SIGN IT THERE, and only
#    then move it into place. ORDER IS THE POINT: `mv` within one filesystem is atomic and is
#    the only step that touches the live extension, so an interruption anywhere leaves the
#    previous working one in place. Copying first and signing afterwards would leave an
#    UNSIGNED .so at the live path, and `cp` writes THROUGH an existing file, so the old one
#    is never intact in between.
HERD84="$HOME/Library/Application Support/Herd/config/php/84"
cp /opt/homebrew/lib/php/pecl/20240924/pcov.so "$HERD84/pcov.so.new"
codesign --force --sign - "$HERD84/pcov.so.new"
mv "$HERD84/pcov.so.new" "$HERD84/pcov.so"
printf 'extension=%s/pcov.so\n' "$HERD84" > "$HERD84/pcov.ini"

# 4. Verify. Runnable on its own at any time. Do NOT write this as `php -m | grep pcov`: the
#    pipe replaces php's exit status with grep's, so a dying php reads as "extension missing"
#    instead of as a broken machine. Let php's own exit status stand.
php -r 'echo phpversion("pcov"),"\n";'
```

**Re-run step 3 after any rebuild or Herd update** — it is the procedure. Re-signing the live
file in place (`codesign --force --sign - "$HERD84/pcov.so"`) is the **emergency** form for a
machine that is already broken, because it has no intact state to fall back on.

**No `herd restart` is needed for tests.** The CLI `php` re-reads the ini scan directory
(`…/Herd/config/php/84/`) on every invocation, so pcov is live for `vendor/bin/pest` the moment
the ini is written. A restart only matters for PHP-FPM behind Herd-served sites, which caches its
config at worker start.

### When every `php` dies with `Killed: 9` (macOS only)

Windows has no `codesign` step and none of this applies there.

**The symptom:** `php -v`, `php -m`, `composer`, and `vendor/bin/pest` all exit 137 with **no
output at all** — in every repository, for every user of the machine, including people who never
ran the install. There is no `Unable to load dynamic library` warning.

**The tell: `php -n` works while plain `php` does not.** `-n` skips the ini files, including the
one that loads pcov, so the pair separates "PHP is broken" from "something PHP loads is broken".
Run this instead of reading for symptoms; it needs no knowledge of the machine's history:

```bash
php -n -v >/dev/null 2>&1; without_ini=$?     # capture each status ONCE, into a variable
php    -v >/dev/null 2>&1; with_ini=$?

if   [ "$with_ini" -eq 0 ];    then echo "ok"
elif [ "$without_ini" -eq 0 ]; then
    echo "BROKEN: php starts without its ini and dies with it - something it LOADS is"
    echo "  killing it. If pcov was installed here, see .claude/skills/pcov-setup/SKILL.md"
    exit 1
else
    echo "BROKEN: php does not start even with -n - the interpreter itself, not an extension"
    exit 1
fi
```

- **Capture each status once, into a variable.** Two `$?` expansions in one line read different
  things — the second reports the first — and a probe written that way has printed `rc=0` for a
  process the shell had just reported as `Killed: 9`.
- **Use no pipe.** `php -m | grep pcov` launders the exit status: a dying interpreter reports as
  a missing extension, the opposite problem with the opposite fix.
- **Do not check the signature instead.** `codesign -v` exits 0 on a file that is about to be
  killed (it validates the signature; it does not predict the load), and the `linker-signed` flag
  also appears on healthy copies that load fine. Only exercising the load detects this.
- **`herd php:list` fails too** ("Error finding executable PHP") because it is written in PHP.
  Enumerate `ls "$HOME/Library/Application Support/Herd/bin/php"*` and run each binary directly;
  only the version whose ini loads the faulting `.so` dies, but the `PATH` shim resolves to it, so
  the whole machine looks broken.
- **A crash report may name the subsystem, and its absence proves nothing.** When
  `~/Library/Logs/DiagnosticReports/php84-*.ips` exists it shows `"namespace": "CODESIGNING"` /
  `SIGKILL (Code Signature Invalid)`. During a twenty-minute outage no report was written at all,
  so treat a missing report as unavailable, never as evidence the fault is unrelated to code
  signing.

**Remedies, in order:** re-run step 3 (stage a fresh copy, sign it, `mv` it into place); if that
cannot run, re-sign the live file in place; as a last resort, remove `pcov.ini`, which restores
`php` at the cost of coverage. Do not expect moving the existing file to help: in one outage a
`mv` or hardlink of it still died while a fresh `cp` of the same bytes loaded.

**The cause is not established.** The fault has also cleared on its own, with the file untouched
(unchanged inode, hash, signature, and `ctime`), so a recovery right after a remedy does not prove
the remedy worked. Record what you ran and when rather than crediting it.

**Served sites fail on a delay.** A Herd PHP-FPM worker that restarts while the bad `.so` is in
place dies the same way, so a `*.test` site can start answering `500` / `502` after the CLI is
already fixed. `herd restart` clears it.

### The blog-post one-liner that does *not* fit Herd

`brew install shivammathur/extensions/pcov@8.4` depends on `shivammathur/php/php@8.4`, a whole
parallel PHP toolchain, not homebrew-core's `php@8.4` or Herd. Use it only if you already run
that tap's PHP; the build-it-yourself route above is self-contained.

### macOS caveats

- **Keep `php@8.4` installed but unlinked** — it is the rebuild toolchain.
- **A Herd PHP minor upgrade (8.4 → 8.5) breaks the `.so`** because the extension API changes.
  Rebuild against a matching brew PHP (`brew install php@8.5 && brew unlink php@8.5`, then steps
  2–4 with the new API directory and `config/php/85`). Patch bumps keep the API and need nothing.
- If a Herd update regenerates `config/php/84/`, re-add `pcov.ini` and `pcov.so` with step 3,
  including the signing.
- **Leaving `pcov.enabled=1` (the default) global is fine.** If you want it dormant anyway, set
  `pcov.enabled=0` in `pcov.ini` and pass `-d pcov.enabled=1` to coverage runs.
- **Don't blame pcov for a CPU-pinned PHP process** without evidence. Sample the stack
  (`sample <pid>`) and read the frames instead of guessing at extensions.

## Windows + Herd

Same shape: a `pcov.dll` built for the Herd Windows PHP's API, NTS, and architecture, loaded by
an `extension=` line in Herd's Windows `php.ini`. Verify with
`php -r 'echo phpversion("pcov"),"\n";'`.

- **Environment block size (mutation only).** The mutate plugin starts one PHP process per mutant
  through Symfony Process, passing the full environment. Once a long session's environment
  exceeds Windows' 32767 UTF-16 code-unit limit, `--mutate` aborts with
  `InvalidArgumentException: The environment block size (NNNNN) exceeds the Windows limit` while
  the ordinary suite still passes. Relaunch with a trimmed environment:

  ```bash
  env -i PATH="$HOME/.config/herd/bin/php84" SystemRoot='C:\WINDOWS' \
    TEMP="$TEMP" TMP="$TMP" \
    php vendor/bin/pest --mutate --path=src --class="RobotCouncil\<Class>"
  ```

## Running mutation and coverage once a driver is present

- **Mutation run:** `vendor/bin/pest --mutate --path=src --class="RobotCouncil\<Class>"`.
  There is no `composer mutate` script. The traps below were read from
  `pestphp/pest-plugin-mutate` v5.0.2; `composer.lock` is not committed, so confirm the installed
  version with `composer show pestphp/pest-plugin-mutate`.
- **Give it a scope.** Without `--path`, `--class`, `--everything`, or a `covers()` / `mutates()`
  call in the tests, v5.0.2 prints an error and exits 1.
- **Pass a relative `--path`, from the package root.** v5.0.2's `Support/FileFinder.php` prefixes
  any path that does not start with `DIRECTORY_SEPARATOR` with the current directory. On Windows a
  drive-letter absolute path does not start with `\`, so it is doubled into a path that does not
  exist → **0 files**. With no `--path`, the plugin uses the `<source>` directories PHPUnit reads
  from `phpunit.xml.dist`, which arrive as absolute paths, so `--class` alone hits this on Windows.
  On macOS absolute paths start with `/` and are unaffected, but `--path=src` works on both.
- **From a linked git worktree**, a run reporting 0 files even with a relative `--path` was
  observed in another repository; it has not been reproduced or explained against v5.0.2. `vendor/` is
  git-ignored, so a new worktree needs its own `composer install` first. If it still finds nothing,
  scope to a relative sub-path and a test file:

  ```bash
  vendor/bin/pest tests/<File>Test.php --mutate --path=src/<Subdir> --class="RobotCouncil\<Class>" --covered-only
  ```

- **`0 Mutations for 0 Files created` means it found nothing to mutate — a setup failure, NOT a
  pass.** It exits 0 unless a minimum score is configured, so the exit code will not tell you.
  Fix the invocation; never read it as green.
- **Coverage report:** `composer test-coverage`, or `vendor/bin/pest --coverage-html=build/coverage`
  for browsable HTML (`/build` is git-ignored).

### Triaging survivors (read them; don't chase a number)

**Don't chase a literal 100%.** The last few percent are usually **equivalent mutants** (bucket
4) whose only "kill" is an annotation in production source or deleting a defensive guard. The
criterion is **no unexplained survivors**, not a score: every survivor is killed, deleted as dead
code, or annotated with the reason it cannot be killed — see
[`adversarial-review`](../../rules/adversarial-review.md). Most survivors fall into four buckets:

1. **Real coverage gap** → add a test or dataset row that exercises that branch (an untested
   boundary `>=`, an untested fallback, positional vs named parameters, a plural/singular branch).
2. **Inert data-table mutant** (`RemoveArrayItem` over a large alias or lookup map) → under
   `--covered-only` these are spawned simply because a test *executed* the data-returning method;
   killing each needs one near-circular assertion per entry. Default: **accept and document the
   rationale in the test file's docblock** — unless the goal is zero survivors, in which case
   generate an exhaustive dataset with a one-off, uncommitted generator script, and hand-verify
   the boundary rows against intent, because they become characterization locks.
3. **Dead code** (the mutant survives because the value is never read, e.g. a computed-then-unset
   local) → **delete the dead code**. Mutation testing is the best dead-code finder you have.
4. **Equivalent mutant** (no input can change observable output — a `(int)` cast on a value
   already typed `int`, an unreachable defensive guard) → remove the redundancy *legitimately*
   (e.g. tighten a PHPDoc array shape so the cast becomes removable and PHPStan stays green) or,
   for code that must stay defensive, annotate it with `// @pest-mutate-ignore` (`: MutatorName`
   to scope it). Several annotation forms are silently inert — prose after the mutator name, a
   trailing space, the wrong placement — so read the annotation section of
   [`adversarial-review`](../../rules/adversarial-review.md) before relying on one.

Whenever survivors are accepted as equivalents, the test file's docblock **must** say which
survive and why, ending on "every killable mutant is dead" (or the equivalent), so the next reader
and the next adversarial pass know the residual score is equivalents, not an untested gap.

## The DRY line

This skill is the home for **coverage-driver setup and the mutation invocation traps**, including
survivor triage. The [`adversarial-review`](../../rules/adversarial-review.md) rule states the
*discipline* (mutation-verify fixed bugs, close surviving mutants, audit kills as well as
survivors) and the annotation-parsing traps, and points here for the driver. Don't restate the
install steps there.
