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
       o = ord(c)
       return (0x1F000 <= o <= 0x1FAFF or 0x2600 <= o <= 0x27BF
               or 0x2B00 <= o <= 0x2BFF or o == 0xFE0F)
   for n, line in enumerate(io.open(sys.argv[1], encoding='utf-8'), 1):
       hits = {c for c in line if bad(c)}
       if hits:
           print(n, sorted('U+%04X' % ord(c) for c in hits))
   PY
   ```

   **It prints code points, never the characters, and that is load-bearing.** A version that printed the matched line crashed on the finding it was reporting. This one, run with `PYTHONIOENCODING=cp1252` over a fixture carrying `U+274C`, `U+26A0 U+FE0F`, and `U+2713` (Python 3.9.6, macOS), reports all three lines and exits 0. `U+FE0F` is in the list deliberately.

   **Validate the harness, not only the expression.** A correct pattern that never meets its input is as blind as no check, and the passing control is what makes it feel covered:
   - **Decode the input.** `perl -ne 'print if /[\x{2600}-\x{27BF}]/'` finds nothing in a UTF-8 file, because without `-CSD` perl reads the bytes separately; with `-CSD` it finds them (perl 5.34, macOS).
   - **Check the fixture as bytes** (`xxd`) before trusting a hit or a miss. A fixture written with `printf '\uXXXX'` under a shell that does not expand the escape carries no glyph and scans clean.
   - **Print the denominator before the scan.** A file count printed first survives a crash and shows the run was incomplete; one printed after dies with everything else.

3. **Already-published records keep what they have.** This rule governs what is written from here; rewriting published issues, pull requests, or releases is a separate, deliberate change.

## Two settled conventions

- **The release breaking-change callout is words:** `**Breaking change** — <impact and required action>`, as [`writing-release-notes`](../skills/writing-release-notes/SKILL.md) and its generator emit it. No glyph and no exception for generated output — a generator writing to a redirected stdout is the case the breakage argument covers most directly.
- **A PR verification list does not use `U+2713`.** Mark a checked item with a `[x]` task-list box or the word `verified`. A check mark is a text symbol rather than a colored emoji, which is why it reads as exempt, but it is absent from `cp1252` and breaks tooling exactly as the warning glyph does.

## What this does not cover

- **A verbatim quotation of a string that contains one.** A quote is evidence. Where the glyph is the thing being identified, prefer naming its code point (`U+1F916`) over reproducing it — exact, searchable, and inert.
- **Anything outside a durable record:** terminal replies and scratch files.

## The DRY line

This file is the standing statement on **emoji in durable records**. It composes with [`impersonal-voice-in-github-artifacts`](impersonal-voice-in-github-artifacts.md), which governs a different leak in the same sentences. A body's sections and vocabulary belong to the [`writing-issues`](../skills/writing-issues/SKILL.md), [`writing-pull-requests`](../skills/writing-pull-requests/SKILL.md), [`writing-commits`](../skills/writing-commits/SKILL.md), and [`writing-release-notes`](../skills/writing-release-notes/SKILL.md) skills; this rule constrains their output rather than restating them.
