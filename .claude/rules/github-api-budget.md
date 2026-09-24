# Rule — spend the REST quota to preserve the GraphQL one

GitHub meters **REST and GraphQL separately** — 5,000 points per hour each, tracked independently. Prefer the **REST** endpoint for anything REST can do, and reserve GraphQL for the work that has no alternative. If a GitHub Projects (V2) board is in use, that means protecting it above all: **Projects V2 is GraphQL-only**, so every GraphQL call spent on an issue is one the board may not have when you need it.

**Why this is a standing order.** The two quotas do not fail together, but `gh`'s ergonomic commands make it look as though they do. `gh issue create`, `gh issue edit`, and even resolving `--assignee @me` are GraphQL-backed, so when the GraphQL quota is gone every one of them errors identically — while thousands of REST calls sit untouched. Only work with no REST surface genuinely has to wait for the reset.

## How to apply

1. **Check before a batch, not after it fails — and read the headers, not `rate_limit`.** Any run that will make more than a handful of calls starts by reading the rate-limit headers off a real call:

   ```bash
   gh api graphql -f query='{viewer{login}}' -i 2>&1 | grep -i '^x-ratelimit'
   ```

   `X-Ratelimit-Resource` names the meter the call was billed to; `X-Ratelimit-Used` / `-Remaining` are the reliable exhaustion figures; `X-Ratelimit-Reset` is a fixed timestamp that actually arrives. Wait for it at a sane interval, never in a tight retry loop.

   **`gh api rate_limit` can be inert, and inert is worse than wrong.** Measured on 2026-09-05 in `UAMS-Web/uams-statamic` work, by four sessions across three machines and two credentials: during a genuine GraphQL exhaustion it reported `used: 0, remaining: 5000` while the headers on the refused call read `5000` and `0`. It is not an inverted gauge you can negate — it reports the same figures whether the quota is full or spent, and its `reset` is recomputed as *now + 3600* on every read, so `sleep until now >= reset` never terminates.

   **A GraphQL rate-limit refusal arrives as `HTTP 200`,** with the error in an `errors` array — not `403`, not `429`. A handler branching on status records it as a success, and so does a pipeline taking its exit code from the last command. Read the body or the headers. **`retry-after` discriminates the two limits:** present, with secondary-limit wording, means a burst limit and a short wait; absent, with `X-Ratelimit-Resource: graphql` and `Used` at the limit, means the hour's quota is gone and only `reset` helps.

2. **Know which `gh` commands are secretly GraphQL.** `gh issue create`, `gh issue edit`, `gh issue close`, `gh pr merge` (it sends a `mergePullRequest` mutation), the `@me` handle lookup, and **all** of `gh project *`, which has no REST surface at all.

3. **Use the REST equivalents for issue and pull-request work.** These keep working when `gh issue` does not:

   | Task | REST |
   | --- | --- |
   | Create | `gh api -X POST 'repos/{owner}/{repo}/issues' -F title='…' -F body=@body.md -f 'labels[]=x' -f 'assignees[]=handle'` |
   | Edit body | `gh api -X PATCH 'repos/{owner}/{repo}/issues/{n}' -F body=@body.md` |
   | Close | `gh api -X PATCH 'repos/{owner}/{repo}/issues/{n}' -f state=closed -f state_reason=not_planned` (or `completed`) |
   | Comment | `gh api -X POST 'repos/{owner}/{repo}/issues/{n}/comments' -F body=@c.md` |
   | Label | `gh api -X POST 'repos/{owner}/{repo}/issues/{n}/labels' -f 'labels[]=x'` |
   | Assign (replace) | `gh api -X POST 'repos/{owner}/{repo}/issues/{n}' -f 'assignees[]=handle'` |
   | Assign (add) | `gh api -X POST 'repos/{owner}/{repo}/issues/{n}/assignees' -f 'assignees[]=handle'` |
   | Open a PR | `gh api -X POST 'repos/{owner}/{repo}/pulls' --input -` with a JSON body (`title`, `head`, `base`, `body`, `draft`) |
   | Merge a PR | `gh api -X PUT 'repos/{owner}/{repo}/pulls/{n}/merge' -f merge_method=<merge\|squash\|rebase>` |

   **The two assignment endpoints differ by one path segment and do opposite things, and a single-account probe cannot tell them apart.** `POST …/issues/{n}` **replaces** the assignee list; `POST …/issues/{n}/assignees` **adds** to it. They diverge only when a request omits somebody already assigned — so a probe against an unassigned issue, or one assigned to the person you are sending, returns identical results from both. Handing work over is exactly the omitting case, and the endpoint whose name matches the intent is the one that does not do it: a hand-over written against `…/assignees` silently leaves **two** assignees.

   **These endpoints return pull requests; the `gh` wrappers do not.** `repos/{owner}/{repo}/issues` and `search/issues` return pull requests alongside issues, marked by a `pull_request` key, while `gh issue list` and `gh search issues` filter them out. The difference is silent and defeats a duplicate check: through the wrapper, the check finds the ticket and conceals the pull request already implementing it.

4. **Dependencies and sub-issues have REST endpoints, and they key on the database id** — the numeric `.id`, not the issue number:

   ```bash
   id=$(gh api 'repos/{owner}/{repo}/issues/<blocker>' --jq '.id')
   gh api -X POST 'repos/{owner}/{repo}/issues/<blocked>/dependencies/blocked_by' -F issue_id=$id
   gh api -X POST 'repos/{owner}/{repo}/issues/<parent>/sub_issues'       -F sub_issue_id=$id
   ```

   Read them back **with a page size**, because both are list endpoints and GitHub defaults to 30:

   ```bash
   gh api 'repos/{owner}/{repo}/issues/<blocked>/dependencies/blocked_by?per_page=100' --jq 'length'
   gh api 'repos/{owner}/{repo}/issues/<parent>/sub_issues?per_page=100'              --jq 'length'
   ```

   A read-back that returns the first 30 of more is **indistinguishable from a complete one**. `sub_issues` demonstrably truncates (on `UAMS-Web/wordpress-importer#358` the bare call returns 30 and `?per_page=100` returns 41), and `dependencies/blocking` truncates the same way; `blocked_by` has not been tested, so pass `per_page` on all three. Past 100, walk `&page=N` explicitly (step 6).

5. **Always pass Markdown from a file** — `-F body=@body.md`. Issue and PR bodies are dense with backticks, `$`, `!`, and fenced blocks that the shell mangles as an inline argument, intermittently enough to look fine until it isn't. The same reason [`writing-issues`](../skills/writing-issues/SKILL.md) mandates `--body-file`.

6. **Never trust a `--paginate` count you piped somewhere.** `gh api --paginate` follows GitHub's `Link: rel="next"`, which GitHub renders in numeric-ID form (`repositories/<id>/issues?page=2`). A Claude Code cloud session's GitHub proxy refuses that shape — `Numeric-ID repository paths are not supported through this proxy (HTTP 403)` — so `gh` prints page 1 and exits 1, and a pipe throws the exit code away: `gh api --paginate '…/issues?per_page=100' --jq '…' | wc -l` prints `100` with pipeline exit 0 when the real count is higher. Use `set -o pipefail` so the failure is at least visible, and prefer walking `&page=N` explicitly until a short page, which keeps every URL in the `repos/{owner}/{repo}/…` form the proxy accepts. Count the **raw** page length, not a filtered one — a `--jq` filter that drops items makes a full page look short and ends the walk early.

7. **`search/issues` cannot phrase-match, and it answers from an index.**
   - A quoted two-word query is an **unordered AND over tokens**, not a phrase (`"my own"` and `"own my"` return the same set), and the endpoint indexes **comments** too. So a count over-counts, and it is **self-incrementing**: the comment citing the number joins the result set. For a phrase count, enumerate candidates and grep each body.
   - "Not found" means *not found in the index as of this query*, and nothing in the response distinguishes a stale view from a genuine absence. A positive control cannot close that gap, since anything old enough to serve as a control is old enough to be indexed. No reliable lag figure exists — one apparent two-minute stale read on 2026-09-05 was not reproduced — so the remedy is a disposition, not a wait: for a duplicate check against something that may be minutes old, read the candidates directly with `GET repos/{owner}/{repo}/issues/{n}` and `…/comments`, and reserve `search/issues` for artifacts whose age you are not relying on.

8. **Do not contort a genuinely graph-shaped read into many REST calls.** One GraphQL query that replaces a dozen round-trips is still the right call when you need related data across many objects. The point is to stop spending GraphQL on *single-object mutations* that REST handles for free, not to ban it — and board state, where a board is in use, can only be read through it.

## The DRY line

This file is the standing statement on **which API surface to spend**. How an issue or pull request is *written* belongs to [`writing-issues`](../skills/writing-issues/SKILL.md) and [`writing-pull-requests`](../skills/writing-pull-requests/SKILL.md); skills that call `gh` inherit this rule rather than restating it. It composes with [`long-running-commands`](long-running-commands.md), which owns bounding a wait rather than choosing what to call.
