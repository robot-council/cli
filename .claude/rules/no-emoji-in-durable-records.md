# Rule — no emoji in durable records

**Emoji do not appear in anything this repo keeps.** That covers issue and pull-request titles, bodies, and comments; commit messages; release titles and bodies, and the `CHANGELOG.md` entries made from them; every file under `.claude/`; and the README.

## Why this is a standing order

**Emoji encode urgency the reader cannot calibrate.** A warning glyph asserts that a sentence matters more than its neighbors without saying why, and once several accumulate the marker carries no information at all. A line that needs a warning marker needs a clause naming what goes wrong.

**And they break, in the places least able to report it.** On Windows a stdout sized to the console code page (`cp1252`) cannot encode them, and neither can it encode `U+2713` or the variation selector `U+FE0F`. A release-notes generator has died exactly this way on its breaking-change callout glyph **after doing all of its work**, leaving a traceback where the notes should have been. How the failure looks depends on what crashed:

- **A generator dies late**, with most of its artifact already written, so the wreckage is visible.
- **A scanner dies on its first finding, before printing it**, so its stdout looks like a clean run. Redirect stderr and drop the exit code, as pipelines do, and a crash is indistinguishable from a pass. Measured: Python 3.12 on Windows under a `cp1252` console left stdout empty; Python 3.9.6 on macOS with `PYTHONIOENCODING=cp1252` left only the line number, exit code 1.

Two further costs: `U+FE0F` is **invisible** and survives a naive strip of the glyph it modifies, so a body that looks clean can still fail a byte comparison; and a glyph is not searchable unless the reader can type it.

## How to apply

1. **Use words.** Bold the clause, or open the sentence with what is at stake. `**Breaking change** — republish the config after upgrading` needs no marker.

2. **Audit the draft before publishing**, including the invisible code point:

   ```bash
   python3 - path/to/draft.md <<'PY'
   import io, sys

   def bad(c):
       # Invisible, and it survives a naive strip of the glyph it modifies.
       if ord(c) == 0xFE0F:
           return True
       # The rule's own reason, asked directly: a character cp1252 cannot encode is one that
       # breaks a Windows console. No interval list to go stale.
       try:
           c.encode('cp1252')
           return False
       except UnicodeEncodeError:
           return True

   # The control, in the same invocation, so a broken predicate cannot report a clean draft.
   must = ['\u2713', '\u2A2F', '\u26A0', '\u274C', '\uFE0F', '\U0001F916']
   mustnt = ['e', '-', '\u2014', '\u2019', '\u00E9', '\u2026', '\u2022']
   missed = ['U+%04X' % ord(c) for c in must if not bad(c)]
   wrong = ['U+%04X' % ord(c) for c in mustnt if bad(c)]
   if missed or wrong:
       print('CONTROL FAILED  missed:', missed, ' false positives:', wrong)
       sys.exit(2)

   for n, line in enumerate(io.open(sys.argv[1], encoding='utf-8'), 1):
       hits = {c for c in line if bad(c)}
       if hits:
           print(n, sorted('U+%04X' % ord(c) for c in hits))
   PY
   ```

   **It prints code points, never the characters, and that is load-bearing.** A version that printed the matched line crashed on the finding it was reporting. `U+FE0F` is handled first and deliberately.

   **It asks the question the rule is about, rather than a proxy for it.** An earlier version listed intervals -- `0x2600-0x27BF`, `0x2B00-0x2BFF` -- and **could not see `U+2A2F`**, which falls in the gap between them and is what Pest prints for a **failing** test. That is the character most likely to be pasted, because quoting a failing run is what a person does when something is wrong. A hand-maintained list of ranges has exactly that failure mode and will have it again; asking cp1252 whether it can encode the character cannot.

   **The control runs before the scan and exits 2 if it fails**, so a predicate that stopped working reports that rather than reporting a clean draft. Its second half matters as much as the first: `-`, an em dash, a curly apostrophe, an ellipsis, a bullet and `e-acute` are all over this repository's prose and all live in cp1252, so a check that flagged them would be abandoned within a day.

   **Validate the harness, not only the expression.** A correct pattern that never meets its input is as blind as no check, and the passing control is what makes it feel covered:
   - **Decode the input.** `perl -ne 'print if /[\x{2600}-\x{27BF}]/'` finds nothing in a UTF-8 file, because without `-CSD` perl reads the bytes separately; with `-CSD` it finds them (perl 5.34, macOS).
   - **Check the fixture as bytes** (`xxd`) before trusting a hit or a miss. A fixture written with `printf '\uXXXX'` under a shell that does not expand the escape carries no glyph and scans clean.
   - **Print the denominator before the scan.** A file count printed first survives a crash and shows the run was incomplete; one printed after dies with everything else.

3. **Already-published records keep what they have.** This rule governs what is written from here; rewriting published issues, pull requests, or releases is a separate, deliberate change.

## Two settled conventions

- **The release breaking-change callout is words:** `**Breaking change** — <impact and required action>`, as [`writing-release-notes`](../skills/writing-release-notes/SKILL.md) and its generator emit it. No glyph and no exception for generated output — a generator writing to a redirected stdout is the case the breakage argument covers most directly.
- **A PR verification list does not use `U+2713`, and quoted test output does not carry `U+2A2F`.** Mark a checked item with a `[x]` task-list box or the word `verified`. Neither is a colored emoji, which is why both read as exempt, and both are absent from `cp1252` and break tooling exactly as the warning glyph does. They arrive together, from the same tool: Pest prints `U+2713` for a passing test and `U+2A2F` for a failing one, so a pasted run carries whichever half is being talked about. Transcribe them as words -- `PASSED`, `FAILED`, `SKIPPED` -- which is what the quotation was for anyway, since the reader cannot act on a glyph their font drops.

## What this does not cover

- **A verbatim quotation of a string that contains one.** A quote is evidence. Where the glyph is the thing being identified, prefer naming its code point (`U+1F916`) over reproducing it — exact, searchable, and inert.
- **Anything outside a durable record:** terminal replies and scratch files.

## The DRY line

This file is the standing statement on **emoji in durable records**. It composes with [`impersonal-voice-in-github-artifacts`](impersonal-voice-in-github-artifacts.md), which governs a different leak in the same sentences. A body's sections and vocabulary belong to the [`writing-issues`](../skills/writing-issues/SKILL.md), [`writing-pull-requests`](../skills/writing-pull-requests/SKILL.md), [`writing-commits`](../skills/writing-commits/SKILL.md), and [`writing-release-notes`](../skills/writing-release-notes/SKILL.md) skills; this rule constrains their output rather than restating them.
