---
name: wcag-contrast
description: >-
  Compute the WCAG 2.x contrast ratio between two colors and report AA/AAA pass-fail for normal
  and large text. Bundles `check-contrast.mjs`, a dependency-free Node CLI that parses `oklch()`,
  hex, and `rgb()` colors (converting OKLCH→sRGB via the OKLab matrix), so it works directly on
  the values browser DevTools report for OKLCH-based themes such as Tailwind v4. Activate whenever
  checking color contrast or accessibility compliance, reviewing dark-mode or light-mode text
  legibility, picking an accessible text/border color against a known background, verifying a
  WCAG AA criterion, or when the user mentions contrast ratio, WCAG, AA/AAA, `oklch`, or whether
  text is readable.
---

# WCAG Contrast Checker

A dependency-free script for checking whether a foreground/background color pair meets WCAG 2.x
contrast thresholds. OKLCH values as DevTools reports them are awkward to eyeball — paste the two
values straight in and get a verdict.

## When to use it

- A reviewer (or you) flags low-contrast text — paste the DevTools `color` and `background-color`
  to get the ratio and AA/AAA verdicts.
- Choosing a dark-mode (or light-mode) text/border color against a known background.
- Verifying an `## Acceptance criteria` item like "label text meets WCAG AA" before claiming it.

## How to run

```bash
node .claude/skills/wcag-contrast/check-contrast.mjs "<foreground>" "<background>"
```

Colors may be `oklch(L C H)` (lightness as `0-1` or `%`), `#rgb` / `#rrggbb`, or `rgb(r g b)` /
`rgb(r,g,b)`. Order does not matter — the ratio is symmetric. Example:

```bash
node .claude/skills/wcag-contrast/check-contrast.mjs "oklch(0.37 0.013 285.805)" "oklch(0.236 0.006 286.015)"
# ratio 1.59:1 — AA normal FAIL  (too dark a label on a dark card)
```

The script exits non-zero when the pair fails AA for normal text (`< 4.5:1`), so it doubles as a
gate in a shell loop or CI step.

## Thresholds it reports

- **AA normal text** — `>= 4.5:1` (body copy, small labels, the usual default).
- **AA large text** — `>= 3.0:1` (>= 24px, or >= 18.66px bold).
- **AAA normal / large** — `>= 7.0:1` / `>= 4.5:1`.

Match the bold/size of the actual element to the right row: a small uppercase label is "normal
text" and needs `4.5:1`, not the `3.0:1` large-text bar.

## Notes

- The OKLCH→sRGB path uses the standard OKLab matrices; out-of-gamut channels are clamped to
  `[0, 1]` before computing luminance, which matches how a browser would render them.
- Alpha (`/ 0.5`) is ignored. For a translucent color, measure the composited value DevTools
  reports rather than the declared one.
