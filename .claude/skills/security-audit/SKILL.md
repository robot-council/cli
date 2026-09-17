---
name: security-audit
description: >-
  Run a multi-agent security audit of the first-party code in the robot-council Laravel package
  (`src/`, `config/`, `database/`, `resources/views/`, `routes/`). Fans out one finder agent per
  security domain (XSS, SSTI, path traversal, SSRF, XXE/DoS, authz, injection, secrets,
  deserialization, validation/mass-assignment), adversarially verifies every candidate against the
  package's trust model (end users reach it only through what the service provider registers, Blade
  `{{ }}` escapes while `{!! !!}` does not, consuming-app developers and Artisan operators are
  trusted), then writes a severity-ranked report to the gitignored `build/` directory. After you
  review it, it discloses approved vulnerabilities privately as draft GitHub security advisories
  (never as public issues) and files approved non-sensitive hardening items as issues following the
  `writing-issues` conventions. Activate when the user asks to "run a security audit", "audit the
  codebase for vulnerabilities", "find security bugs", "turn Claude loose on security", "scan for
  XSS/SSRF/injection", or invokes `/security-audit`. This is a broader, whole-repo complement to the
  diff-scoped `/security-review` command.
---

# Security audit (multi-agent, report-first)

A repo-tailored security audit for `robot-council/core`. It runs a bundled multi-agent
**workflow** that finds → adversarially verifies → triages, hands you a report to review, and only
then discloses or files anything. The verification step is the point: most generic Laravel
"findings" are false positives — Blade `{{ }}` already escapes, the query builder already binds
values, a config value is set by a trusted developer, and a class the service provider never
registers is unreachable — so every candidate is checked against the package's real trust
boundaries before it reaches you.

**The repository is public.** Two consequences are built into the procedure below: the report is
written only to the gitignored `build/` directory and is never committed, and a vulnerability is
disclosed privately through a draft security advisory, never described in a public issue, pull
request, commit message, or comment.

**A sanitizer is only a protection while its failure path does not return the raw input.** A
fallback of the form `catch (\Exception $e) { return $value; }` fails open on anything in the
`Exception` family, and that family is wider than it looks: Laravel's `HandleExceptions`
bootstrapper converts PHP warnings and notices (not deprecations) into `ErrorException` in every
environment, so a vendor `trigger_error()` inside the sanitizer takes the fail-open path. `TypeError`
and the rest of the `Error` family are not caught by `catch (\Exception)` and surface as a 500
instead — loud, but still a sanitizer that did not sanitize. So when auditing a sanitizer, ask
*"what makes this throw, with which class, and what does the caller emit then"* alongside *"what
gets past it"*.

## Invocation

| Command | Scope |
| --- | --- |
| `/security-audit` | Full first-party sweep (default). |
| `/security-audit src/Commands config` | Restrict to the given paths (and code they call into). |
| `/security-audit --since main` | Audit only what changed vs a ref (broader than `/security-review`). |
| `/security-audit --quick` | Skip the workflow; do a single-agent pass (cheaper, shallower). |

## Procedure

### 1. Resolve scope → build `args` for the workflow

- **Default / no args** → `{ mode: 'full' }`.
- **Path args** (`src/Commands …`) → `{ mode: 'paths', files: [<those paths>] }`.
- **`--since <ref>`** → run `git diff --name-only <ref>...HEAD`, keep only first-party paths
  (`src/`, `config/`, `database/`, `resources/views/`, `routes/`), and pass
  `{ mode: 'diff', files: [<changed first-party files>], baseRef: '<ref>' }`. If the diff is
  empty, say so and stop. (`git` takes the same command and prints forward-slash paths on both
  Windows and macOS — keep the slashes as-is; pass them through unchanged.)

### 2. Run the workflow

Invoke the bundled script (this skill's instruction to call `Workflow` is the opt-in — you do
**not** need to ask the user again):

```
Workflow({ scriptPath: ".claude/skills/security-audit/security-audit.workflow.js", args: <from step 1> })
```

It fans out 10 domain finders, runs a 2-lens adversarial verifier (sink analysis + reachability) on
each candidate, drops anything a skeptic confidently refutes, keeps genuinely undecidable items as
`uncertain`, then returns a triaged `{ summary, findings[], gaps[], counts, mode }` object. Watch
live progress with `/workflows`. The package is young, so a domain with no surface yet (for
example, routes before the package ships any) is expected to come back empty.

> `--quick` mode: skip the Workflow call. Instead read the domain list and trust-model section
> from [security-audit.workflow.js](security-audit.workflow.js) and do a single read-only pass
> yourself, producing the same report shape. Use only when the user explicitly wants it cheap.

### 3. Write the report

Write `build/security-audit-<today>.md`, where `<today>` is the current date from the session
context (e.g. `2026-06-12`) — read it from context, don't shell out for it (`date` and `Get-Date`
differ by OS). The workflow can't stamp dates itself.

**The report must never be committed.** It may live in the working tree only because `/build` is in
`.gitignore`. Before writing, confirm that still holds:
`git check-ignore -q build/security-audit-<today>.md` exits `0` when the path is ignored; on any
other exit, stop and ask the user where the report should go. Never `git add -f` it, and never copy
it, or any part of it, anywhere tracked.

Structure:

- **Title + meta line** — date, scope/mode, and `counts` (candidates / confirmed / uncertain / refuted).
- **Executive summary** — the workflow's `summary`.
- **Findings** grouped by severity (`## Critical` → `## Info`). For each:
  `### <title>` then a bullet list — **File** (`[path:line](path#Lline)`), **CWE**, **Severity**
  (+ `(was X)` if the verifier adjusted it), **Confidence**, **Status**, **Data flow**,
  **Exploit scenario**, **Preconditions**, **Recommendation**.
- **Needs manual review** — the `uncertain` findings, with the dynamic check each one needs.
- **Coverage gaps** — the workflow's `gaps[]`.

Use clickable `[path](path#Lline)` links (per the VSCode-extension convention), not backticks, for
file references.

**Escape finding text.** Security findings routinely contain literal `<script>`, `</script>`, and
other tags in their titles, summaries, data-flow traces, and exploit payloads. Markdown renderers
pass raw HTML through, so an unescaped `<script>` in a heading opens a real script element and
swallows everything until the next `</script>` — breaking the rendered report. HTML-escape `<` → `&lt;`,
`>` → `&gt;`, and `&` → `&amp;` in every agent-supplied text field before writing it (do **not** escape
the markdown you author yourself — links, headings, bullets). The same applies to advisory and
issue bodies in step 5.

### 4. Summarize and offer to disclose

Print a compact severity table (title · severity · file:line · confidence) and the gaps. Then ask
which findings to act on — **do not create any advisory or issue without explicit approval.** Offer
the natural groupings: "all confirmed", "confirmed high+", "let me pick", or "none for now". State
the route you propose for each finding (private advisory or public hardening issue, per step 5), so
the approval covers the route as well as the finding.

### 5. Disclose approved findings

#### Vulnerabilities → a draft repository security advisory, never an issue

Every `confirmed` finding, and every `uncertain` one whose write-up describes an exploit path, is
sensitive. If you are unsure whether a finding is sensitive, treat it as sensitive.

GitHub's REST API creates a **draft** repository security advisory with
`POST /repos/{owner}/{repo}/security-advisories` (checked against GitHub's REST reference,
"Create a repository security advisory"). A draft is visible only to the repository's
administrators and security managers and to collaborators on the advisory. The caller must be an
administrator or security manager of the repository, and a classic token needs the `repo` or
`repository_advisories:write` scope.

1. **Check for an existing advisory first.** For an administrator, the list includes drafts:

   ```bash
   gh api "repos/robot-council/core/security-advisories?per_page=100" --jq '.[] | "\(.state)  \(.ghsa_id)  \(.summary)"'
   ```

   Also run the "Check for an existing issue first" searches from
   [`writing-issues`](../writing-issues/SKILL.md), in case the problem was already reported in
   public — searching discloses nothing. Vary the terms across the vulnerability class / CWE
   (`SSRF`, `XSS`, `CWE-79`), the affected file / symbol, and a symptom phrase. If anything
   matches, link it for the user and skip.
2. **Write the request body** with the Write tool to `build/security-advisory-<short-slug>.json`
   (gitignored for the same reason; confirm with `git check-ignore -q` as in step 3):

   ```json
   {
     "summary": "<finding title, at most 1024 characters>",
     "description": "<Markdown body>",
     "severity": "high",
     "cwe_ids": ["CWE-79"],
     "vulnerabilities": [
       {
         "package": { "ecosystem": "composer", "name": "robot-council/core" },
         "vulnerable_version_range": "<= 1.2.0",
         "patched_versions": null
       }
     ]
   }
   ```

   - `description` — a lede (`Found by the security audit on <date>.`), then `## Root cause`,
     `## Reproduction` (the exploit scenario), `## Proposed fix`, a `## Acceptance criteria` task
     list of observable outcomes **ending in a test item** (a regression test that fails on the
     vulnerability and passes after the fix), and `## References` with the file paths.
   - `severity` — `critical`, `high`, `medium`, or `low`. The API has no `info` level; an
     info-level finding is not an advisory. (`cvss_vector_string` may be set instead, never both.)
   - `vulnerabilities` is required. Set `vulnerable_version_range` to the affected released range
     (read the releases with `git tag --list`), or `null` when unknown or nothing is released yet;
     leave `patched_versions` `null` until a fix ships.
3. **Create the draft** and give the user its URL:

   ```bash
   gh api -X POST repos/robot-council/core/security-advisories --input build/security-advisory-<short-slug>.json --jq '.html_url'
   ```

   If the call fails (a permission or scope error, or a validation error), do **not** fall back to
   an issue. Report the error and ask the user how to disclose.
4. **Stop at the draft.** Publishing it, requesting a CVE, adding collaborators, and opening the
   advisory's temporary private fork are the user's decisions.

#### Non-sensitive hardening items → a normal issue, with approval

A hardening item is one whose issue text tells a reader nothing they could exploit against any
released version — for example, defense in depth where no current input reaches the sink. For each
approved one, follow the [`writing-issues`](../writing-issues/SKILL.md) skill conventions exactly
(invoke it), filing into `robot-council/core`:

- **Title** — imperative, fix-oriented, with inline-code markup, no trailing period
  (e.g. ``Pass `Process` commands as arrays instead of shell strings``).
- **Body** — lede (often `Spun off from the security audit on <date>.`), the sections
  `writing-issues` prescribes for the archetype, and a mandatory `## Acceptance criteria` task list
  of observable outcomes **ending in a test item**. Add `## References` linking the absolute-branch
  file path.
- **Labels** — only labels that already exist, per `writing-issues`. If no `security` or severity
  label exists, do not create one.
- **Never** add a "generated by AI" disclaimer to the issue body.
- **Check for an existing issue first**, exactly as in the advisory route above. Link or skip any
  match instead of opening a duplicate.

## Guardrails

- **Read-only.** This audit performs static analysis only. Do not modify source, run the tests or
  the package's commands, or make network requests to "prove" a finding. Dynamic confirmation
  belongs in the advisory's or issue's reproduction steps, run deliberately by a human or a
  follow-up task.
- **Public repository.** The report and advisory request bodies live only under the gitignored
  `build/`. Never commit them, and never describe an undisclosed vulnerability in an issue, pull
  request, commit message, or comment.
- **Trust-boundary first.** Severity always reflects *who controls the input* — an anonymous end
  user of a consuming application vs. an authenticated end user vs. the consuming application's
  developer (trusted: config, published views and migrations, container bindings) vs. an operator
  running the package's Artisan commands. Reachability is judged under the package's shipped
  defaults, because the package cannot assume the consuming app's middleware. The workflow enforces
  this; preserve it in the report, advisories, and issues. Don't inflate a developer-configured or
  operator-only issue into an anonymous critical.
- **Cost.** The full workflow spawns dozens of agents. For a quick look at one file, prefer
  `--quick` or a scoped path. The diff mode (`--since`) is the cheapest comprehensive option.
- **Scope.** First-party only: `src/`, `config/`, `database/`, `resources/views/`, `routes/`.
  `vendor/`, `workbench/`, `build/`, and `.claude/` are out of scope. For dependency CVEs use
  `composer audit` instead (`--no-dev` limits it to what ships to consumers), and mention that if
  the user asks about third-party risk. `composer.lock` is not committed, so the audit covers only
  the local resolution; consumers resolve their own versions within the `require` constraints.
- **Cross-platform (Windows + macOS).** This skill works identically on both: every path here and in
  the workflow uses forward slashes (valid on Windows and macOS), the `scriptPath` is repo-relative,
  and the only shell-outs are `git`, `gh`, and `composer` (same commands, same forward-slash output
  on both). The workflow script runs in the harness JS sandbox — no Node/OS dependency, no
  `Date.now()`/`Math.random()`, no filesystem access from the script itself. Keep paths
  forward-slash even on Windows; don't convert to backslashes and don't invoke OS-specific shell
  commands.
