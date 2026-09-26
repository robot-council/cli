# robot-council/cli

[![CI](https://github.com/robot-council/cli/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/robot-council/cli/actions/workflows/ci.yml?query=branch%3Amain)

The command line for [Robot Council](https://github.com/robot-council/core), a coordination service for fleets of AI coding agents. It is a [Laravel Zero](https://laravel-zero.com) application, and it sits beside the package the way `statamic/cli` sits beside `statamic/cms`.

Four commands, designed in [#1](https://github.com/robot-council/cli/issues/1) and [#13](https://github.com/robot-council/cli/issues/13):

- **`robot-council enroll`** — enroll this machine. It requests a device code, prints the user code and the verification URL, and polls until a developer approves. The credential it receives is stored in the OS keychain or a user-only file, and never printed.
- **`robot-council mcp`** — the stdio MCP bridge. An agent harness launches it, and it comes up **without joining the fleet**: its one tool is `join`, and the fleet's coordination tools appear once an agent calls it. Once joined, it renews its own session token, so a token expiring needs no restart and no human. Under Claude Code, `--keep-warm=<minutes>` has it wake an idle session to keep its prompt cache warm.
- **`robot-council pending`** — print the fleet events waiting for this harness, and clear them. The bridge reads the change feed while it runs and leaves anything that concerns its own session here; a harness's stop hook runs this at a turn boundary so an agent finds out without being asked. Prints nothing and exits `0` when the fleet has been quiet. It also records when the turn ended, for a bridge keeping the session's cache warm.
- **`robot-council api`** — the fallback for anything the bridge does not cover.

- **`robot-council new`** — create a fleet service in this directory, the way `statamic new` creates a site.

Running a seat once it is set up -- starting it so the fleet can wake it, what it receives, restarting it, and checking it is healthy -- is in the [operator's guide on the wiki](https://github.com/robot-council/cli/wiki).

## Requirements

- PHP 8.4 or later

## Installing

Install it into a directory of its own, as **versions side by side behind a launcher**, so upgrading never replaces a file a running bridge holds ([#280](https://github.com/robot-council/cli/issues/280)):

```bash
composer create-project robot-council/cli "$HOME/.robot-council-setup" --no-dev
php "$HOME/.robot-council-setup/robot-council" upgrade --root="$HOME/.local/robot-council"
rm -rf "$HOME/.robot-council-setup"
```

On Windows, in PowerShell:

```powershell
composer create-project robot-council/cli "$HOME\.robot-council-setup" --no-dev
php "$HOME\.robot-council-setup\robot-council" upgrade --root="$HOME\.local\robot-council"
Remove-Item -Recurse -Force "$HOME\.robot-council-setup"
```

That installs the newest release under `~/.local/robot-council/versions/<version>/`, and puts the launcher in `~/.local/robot-council/bin`. Put that directory on your `PATH`, and `robot-council` is available everywhere, which is what the harness setups below assume. The launcher starts the newest installed version each time a process starts.

**On Windows, point a harness at the launcher through `php`**: `command` is `php`, and `args` start with the absolute path to `~\.local\robot-council\bin\robot-council.php`, then `mcp`. A harness that starts processes without a shell cannot run the `.cmd` shim. **Never point it at a file under `versions\`**: the harness would go on starting that one version, and fail to start at all once an upgrade has removed it.

### Upgrading

```bash
robot-council upgrade
```

**It needs nobody at the machine, and it is safe with every session's bridge running**, including on Windows. It installs the new version into a directory of its own and removes nothing a running process holds: a running bridge keeps the version it started with, and each session picks up the new one the next time it starts. A session can run it unattended. An old version still in use is kept and removed by a later `upgrade`, once nothing runs it. `robot-council upgrade <version>` installs a particular one.

**The layout, for reference:**

| path | what it is |
| --- | --- |
| `bin/robot-council`, `bin/robot-council.cmd` | the shims on `PATH`, for macOS and Linux, and for Windows |
| `bin/robot-council.php`, `bin/select.php` | the launcher, which a routine upgrade never replaces |
| `versions/<version>/` | one installed version; `.in-use` in it is locked by every process running it |

### Moving from a single-directory install

Earlier instructions installed with `composer require robot-council/cli` into one directory, and upgraded with `composer update` in place. **On Windows, upgrading that way while any bridge is running breaks the install partway**: Windows will not delete a file a running process holds open, so Composer removes the old package, fails on the one locked file, and leaves `vendor/bin/robot-council` answering `Permission denied`. macOS and Linux allow it, which is why the same command looked safe there.

To move to the versioned layout, run the three commands above with `--root` naming your existing install directory, then point `PATH` at its `bin`, in place of `vendor/bin`. The old files are not touched, so it is safe with bridges running; remove the old `vendor/` once no session is using it.

**To recover an install already broken the old way:** close every session on the machine that runs a bridge from it, including the one that ran the upgrade, whose own bridge holds the lock too. Confirm no `php` process is still running `robot-council mcp`. Then, from a plain terminal, run `composer install -d <install directory>` -- the lock file already names the version you asked for -- check `robot-council --version`, and relaunch the sessions. Then move to the versioned layout, so it does not happen again.

**Use `v0.4.2` or later.** Every release before it failed the first command that made an HTTP request -- `enroll`, `mcp`, and `api` all stopped at `Target class [Illuminate\Http\Client\Factory] does not exist.` -- because a package it needs arrived only through a development dependency ([#237](https://github.com/robot-council/cli/issues/237)).

### Why not `composer global require`

A global install shares one dependency graph with every other globally installed tool, and this command line needs Laravel 13: `laravel-zero/framework` v13 requires `illuminate/support` and `illuminate/collections` `^13.24`. Any global tool that caps Laravel below 13 makes the two impossible to install together, and a directory of its own cannot have that problem.

**`statamic/cli` before 3.6.4 is one of them.** 3.6.1 through 3.6.3 require `illuminate/support ^10.0|^11.0|^12.0`; 3.6.4 accepts Laravel 13. Composer never names `statamic/cli` when it refuses. Its refusal names other packages instead -- in the runs recorded here `guzzlehttp/guzzle`, `guzzlehttp/psr7` and `illuminate/collections`, each `fixed to <version> (lock file version) by a partial update`, with `laravel-zero/framework` or `illuminate/http` as the package requiring them -- and `--with-all-dependencies` changes that only to `… but these were not loaded, likely because it conflicts with another require.` While `statamic/cli` is below 3.6.4, no flag gets past it: `-W` widens the update to the dependencies of the package being required, and `statamic/cli` is not one of them.

To install globally anyway, update `statamic/cli` first, then require this package with `-W`, because the other packages the two share stay at their locked versions otherwise:

```bash
composer global update statamic/cli
composer global require -W robot-council/cli
```

That works until the next global tool with a Laravel ceiling brings the same failure back, which is why the directory above is the recommendation.

**Verified 2026-09-24** on macOS 26.6.2 with PHP 8.4, each in a scratch `COMPOSER_HOME`. The first set is a copy of a real developer's global `composer.json` and `composer.lock` -- `statamic/cli ^3.6` locked at 3.6.1, and `laravel/cloud-cli ^0.6.1` -- installed from its lock:

| step, in order | result |
| --- | --- |
| `composer global require robot-council/cli` | fails, exit 2 |
| the same with `-W --dry-run` | fails, exit 2, `conflicts with another require` |
| `composer global update statamic/cli` | moves it from 3.6.1 to 3.6.4 |
| `composer global require robot-council/cli` | fails, exit 2, other packages `fixed … by a partial update` |
| `composer global require -W robot-council/cli` | installs `v0.4.2`, exit 0 |

A fresh global set resolved today -- `statamic/cli` 3.6.4 and `laravel/cloud-cli` 0.6.1 -- installs with a plain `composer global require`, exit 0.

The install in a directory of its own was verified beside a global set holding `statamic/cli` 3.6.1, where a global install fails with exit 2: the single-directory form this section recommended then (`composer require robot-council/cli --working-dir=<directory>`) resolved `^0.4.2` and exited 0, `robot-council --version` printed `robot-council v0.4.2`, and `robot-council enroll -v` against an unreachable host failed at the network with `cURL error 6` rather than in the container. That last step is the one that shows the HTTP client resolved, which `list` alone does not.

**The earlier record was narrower than it read.** Of `composer global require robot-council/cli`, this section said: *"**Verified 2026-09-22** on macOS 26.6.2 with PHP 8.4, into a throwaway `COMPOSER_HOME`: that line resolves `^0.2.0`, exits 0, writes `vendor/bin/robot-council`, and `robot-council list` shows `about`, `api`, `enroll`, `mcp`, `new`, and `pending`."* That was true. But an empty `COMPOSER_HOME` is the one place a conflict with another global tool cannot arise, and `list` never resolves the HTTP client, so the same run passed on a release that could not make a request. It showed the command installs where nothing else is installed, not that it works on a developer's machine ([#236](https://github.com/robot-council/cli/issues/236)).

## Creating a fleet service

```bash
robot-council new my-fleet
```

It creates a Laravel application in the current directory, requires `robot-council/core`, runs the package's installer, and migrates. Then it prints the exact OAuth callback URL to paste into GitHub, takes the client ID and secret back, and asks who may sign in.

**The allowlist is asked for as logins and stored as numeric IDs.** The service checks numeric GitHub account IDs, because a login can be renamed and then claimed by somebody else -- but nobody should have to go and find a number, so each login is resolved once, here, and the mapping is printed before it is written.

`--app-url` overrides where the application will be served, which decides the callback URL; it defaults to Herd's `http://<name>.test`. `--skip-github` scaffolds without asking anything.

## Getting a machine onto a fleet

**Before anything else: the developer's GitHub account has to be on the fleet's allowlist.** The service checks it on the enrollment page *and* on the approve and deny routes, so somebody who is not on it cannot approve their own enrollment, or anybody else's. An administrator adds them to `ROBOT_COUNCIL_DEVELOPERS` and redeploys; until that has happened, everything below stops at a code nobody can approve.

**Run this yourself, in your own terminal.** It is half a browser task, so handing it to an agent leaves the agent blocked on a code only a person can approve.

```bash
robot-council enroll \
  --service=https://your-fleet.example.com \
  --harness=claude \
  --machine-label=your-machine
```

It prints a code and a URL, and waits. An allowlisted developer signed in to that fleet opens the URL, enters the code, and approves; enrollment finishes on its own. Then wire a harness to the bridge, below.

**Enroll once per harness, not once per machine.** A credential belongs to one harness, so a machine running both Claude Code and Cursor enrolls twice:

```bash
robot-council enroll --service=https://your-fleet.example.com --harness=claude
robot-council enroll --service=https://your-fleet.example.com --harness=cursor
```

Re-enrolling a harness **replaces** that harness's credential and leaves the others alone. Worktrees do not need one each: several checkouts under one harness share its credential and are told apart by the repository and work location each session reports, which are read from the checkout (see [joining the fleet](#joining-the-fleet)).

**Pass `--machine-label`, and pass the same one for every harness on the machine.** It is how a person tells sessions apart on the dashboard, so it should be the name you would say out loud -- `josh-office`, `josh-home`. Omitted, it is derived from `gethostname()` with a trailing `.local` removed and every character outside `[A-Za-z0-9._-]` **silently dropped**, which is the case worth avoiding: a machine called `Josh's MacBook Pro.local` enrolls as `JoshsMacBookPro`, which is plausible enough that nobody questions it and is not what anyone would have chosen. Two harnesses given different labels appear as two machines. The bound is 64 characters.

**On macOS, this needs `v0.4.0` or later.** Before it, `security` prompted on the terminal for the keychain password, ignored what the command piped to it, and the write timed out after fifteen seconds -- so enrolling from an interactive shell could not store a credential at all, and the only path that worked was one with no controlling terminal. [#207](https://github.com/robot-council/cli/issues/207) has the measurement; `robot-council --version` says which you have.

`robot-council enroll --help` lists the other options.

## Giving the service its secrets

**This command line does not handle them, on purpose.** Every credential it touches stays on the
machine or goes to the fleet that machine is enrolled against, and nowhere else -- and a harness
launches this binary as a child process, so a command that took a secret and posted it to a third
party would be an exfiltration path sitting in an executable agents already run. Use the host's own
tooling. On Laravel Cloud that is:

**Enter these one at a time, not as a pasted block.** The reason is the first line: `read` takes its
input from standard input, and when a multi-line block is pasted, standard input *is* the rest of the
paste. Pasted together, `read` swallows the following line and stores it as part of the value.

```bash
read -rs URL
```

Run that alone, then paste the URL and press Enter. Check it before sending it anywhere:

```bash
printf 'len=%s starts=%s\n' "${#URL}" "${URL:0:12}"
```

`starts=https://hook` and a length around 78. The prefix is not the secret part, so this is safe to
read aloud, and it is the step that catches a value that arrived with something on the front of it.

```bash
printf '%s' "$URL" | cloud secret:create --name=ROBOT_COUNCIL_SLACK_WEBHOOK_URL; unset URL
cloud environment-secret:attach production <the id it printed> -n
cloud deploy -n
```

`read -rs` does not echo and keeps the value out of shell history; piping it to stdin keeps it out of
the process list, which `--value=` would not. `-n` skips the CLI's own interactive prompts. The last
line matters: a secret reaches the running application only on the next deploy.

**Type the id, not the angle brackets.** `<the id>` at a shell prompt is input redirection, and the
shell answers `No such file or directory` for a file named after whatever is inside them.

**A webhook the service cannot reach fails as a retrying job, not as silence.** The mirror bounds
retries by a one-hour deadline rather than an attempt count, so a wrong value leaves jobs pending and
writes `robot-council could not reach the Slack webhook.` to the log -- without the URL in it. Read
the log rather than the channel: an empty channel looks the same whether the mirror is off, broken,
or working and nobody has narrated.

The same two values are all the service needs: `ROBOT_COUNCIL_SLACK_WEBHOOK_URL` for the event
mirror, and the GitHub OAuth client id and secret, which `robot-council new` writes to `.env` when
it scaffolds. Everything else is ordinary configuration.

## Wiring the bridge into a harness

**A harness never receives the credential.** Its configuration carries the service URL and nothing else — the bridge reads the credential itself, from the keychain, in its own process. That is the reason the bridge exists rather than the harness speaking to the service directly: a token in harness configuration is a token in every transcript that configuration is dumped into, and agents dump their configuration.

So the only secret-adjacent thing below is a URL, and each harness is configured the same way: launch `robot-council mcp` as a stdio server, with `ROBOT_COUNCIL_SERVICE` in its environment.

**Put `ROBOT_COUNCIL_HARNESS` beside it.** One machine holds one credential per harness, so the bridge has to know which harness it is. It asks `--harness`, then `ROBOT_COUNCIL_HARNESS`, then `laravel/agent-detector` — and **refuses if none of them answers**, rather than presenting whichever credential happens to be there. Detection works where a harness exports a variable the detector knows; naming it in the configuration costs one line and does not depend on that.

Pass `--project` as well where one harness works several checkouts, so the fleet can tell the sessions apart. See [naming a project](#naming-a-project) for what to put in it.

### Joining the fleet

**Launching the bridge does not join the fleet** ([#127](https://github.com/robot-council/cli/issues/127)). Opening an editor is not a decision to join, so the bridge comes up with no session and no credential read. It answers the harness's handshake itself and lists one tool, `join`, which an agent calls when the operator asks it to, or when its instructions say the checkout works with the fleet. Joining reads the credential, starts the session, and tells the harness the tool list changed, so the fleet's tools are callable in the same turn.

**There is no `robot-council join` command, and that is the part to say out loud.** `join` is an MCP tool, so nothing is typed at a shell: in a session with the bridge wired up, you ask the agent, and the agent calls the tool. All of these work, because the agent maps what you said onto the tool's arguments:

```
join the fleet
join the fleet as coordinator
join the fleet for owner/name, work location primary
```

`join` takes three optional arguments:

| argument | meaning |
| --- | --- |
| `role` | `build`, `ci` or `coordinator`. Anything but `build` is **a request an administrator decides**, not a grant: the session holds `build`'s abilities until it is approved. |
| `repository` | `owner/name`, when the checkout cannot say or says wrong. Read from the checkout otherwise. |
| `work_location` | which working copy this is. Read from the checkout otherwise. |

**Both are read from the checkout, so usually you name neither.** `repository` comes from `git remote get-url origin` and resolves only for GitHub hosts; `work_location` is a linked worktree's own name, or `primary` in a main checkout. From a worktree at `robot-council-cli-a` whose `origin` is this repository, a bare `join the fleet` reports `robot-council/cli` and `robot-council-cli-a` without being told either.

Name them when the default is wrong, which is three cases and no others:

- **The checkout cannot say.** No `origin`, a remote that is not GitHub, or a git that hangs leaves `repository` null. The session still joins; it just cannot say where it is.
- **The checkout says something true but unhelpful**, such as a fork, where the work is tracked under the upstream name.
- **The directory name is not the label you want.** A work location exists to be compared *across machines* -- `josh-office` and `josh-home` both running `a` of one repository -- so a checkout called `robot-council-cli-a` on one and `cli-a` on the other reads as two places and defeats the comparison.

**`work_location` is lowercase.** The service takes `^(?!\.+$)[a-z0-9_.][a-z0-9._-]*$` and at most 32 characters, so `robot-council-cli-a` is accepted and `Robot-Council-A` is refused. `repository` is the more permissive of the two: `owner/name`, mixed case allowed, at most 140 characters. Either refusal happens at the service, after the session has started, so it arrives as a join that reports a problem rather than a silently wrong label.

**A machine that cannot join still comes up.** With no credential, `join` answers with what is missing, in a tool result the agent can relay, where the bridge used to exit at launch with a line most harnesses bury. Calling `join` a second time is refused and leaves the session already held open.

**To join every time a checkout launches**, add `--auto-join` after `mcp`, and `--role=<role>` beside it to ask for a role; that role is still only a request. The outcome of an automatic join goes to stderr, prefixed `robot-council:`.

**A session that runs parallel work through subagents can declare a capacity**: how many tasks it will hold at once ([#302](https://github.com/robot-council/cli/issues/302)). Pass `capacity` to the `join` tool, or `--capacity=<n>` beside `--auto-join`. Omitted, the fleet holds a session to one task at a time. The fleet caps a declared capacity at the seat's limit, which the developer sets in the fleet's seat settings, and at 16. The join result says what is in effect, and says so plainly when the cap lowered it. A value that is not a whole number of 1 or more is refused before any request: from the `join` tool with an error, and from `--capacity` by not joining automatically. It needs `robot-council/core` v0.7.0 or later; an older fleet ignores it, and the join result says it reported none.

**The agent reports the branch a task is built on, once that branch exists** ([#238](https://github.com/robot-council/cli/issues/238)). Taking up a placed task is the agent's own `task_start`, which moves it to `in_progress`. The branch comes later: a lane usually creates it after starting, so the fleet's `task_branch` tool takes it then, from the session holding the task. The agent calls it with what `git branch --show-current` prints in the checkout it is working in, and does not call it from a detached `HEAD`, where that prints nothing. The bridge does not fill the branch in itself: it runs where the harness launched it, which is not the agent's checkout once the agent works in a worktree. `task_branch` exists on a service that includes [`robot-council/core#333`](https://github.com/robot-council/core/issues/333).

### Claude Code

**You run this yourself too.** It edits Claude Code's own configuration, which an agent would be changing underneath the process it is running in, and the change does not take effect until a restart it cannot perform on itself.

```bash
claude mcp add -s user robot-council \
  -e ROBOT_COUNCIL_SERVICE=https://your-fleet.example.com \
  -e ROBOT_COUNCIL_HARNESS=claude \
  -- robot-council mcp
```

**`-s user` is what makes this once per machine.** `claude mcp add` defaults to `-s local`, which registers the server for the current project only, so the same command has to be repeated in every checkout. The three scopes are `local`, `user` and `project`; `user` is the one that matches a bridge reading one machine's credential.

That works because nothing in the command above is checkout-specific: the repository and the work location are read from whichever checkout the bridge is launched in, so one configuration serves them all. A per-checkout scope is needed only for a per-checkout *flag*, which today means `--project` alone -- and that label is superseded by the two fields, as [naming a project](#the-label-is-now-two-fields-and-both-are-read-from-the-checkout) records.

**Then restart Claude Code.** A running session does not pick up a newly added MCP server: every scope is read at startup, and `/mcp` reconnects servers it already knows rather than loading new ones. (`/reload-plugins`, on 2.1.246 and later, applies to servers a *plugin* provides, not to one added here.) Until the restart, the bridge is configured and absent, which looks exactly like a bridge that is broken.

**Verified 2026-09-21**, on Claude Code 2.1.236 and macOS 26.6.2, against a live fleet, with three configurations differing only in that variable:

| `ROBOT_COUNCIL_HARNESS` | `claude mcp list` |
| --- | --- |
| `claude` | `Connected` |
| *omitted* | `Connected` — Claude Code exports a variable the detector reads, so detection alone is enough here |
| `cursor`, which this machine has not enrolled | `Failed to connect` |

These runs were measured before [#127](https://github.com/robot-council/cli/issues/127), when the bridge joined at launch; today the same command comes up unjoined, as [joining the fleet](#joining-the-fleet) describes. To join at launch instead, put `--auto-join` after `mcp`.

So for **this** harness the variable is belt and braces rather than required. It is in the command above anyway, because it is the line that stops working depending on somebody else's environment, and because the third row is what a wrong value looks like: a clean refusal rather than somebody else's credential.

**Verified 2026-09-18**, on Claude Code 2.1.236 and macOS 26.6.2, against a live fleet: `claude mcp list` reported `robot-council` as `Connected`, and a raw `initialize` plus `tools/list` over the same command served all 18 tools with nothing but protocol on stdout.

**`tools/list` paginates, and the first page is 15.** It returns a `nextCursor` — base64 of `{"offset":15}` — and the remaining three (`events_narrate`, `directive_post`, `presence_heartbeat`) come back only when that cursor is passed. A first page read as a total looks exactly like a complete answer, and the number that would reveal otherwise is the one the page does not carry. This is recorded because it was gotten wrong here first, and read as a stale deployment.

Two controls ran beside it, because a health check that answers `Connected` for anything answers nothing: the same command at a path that does not exist failed with `ENOENT`, and **the real binary with `ROBOT_COUNCIL_SERVICE` omitted failed with `CONNECTION_CLOSED`**. The second is the one that matters — it is the same binary, missing only the variable, so the passing reading is evidence the variable reached the process and a session started on the service.

If `robot-council` is not on your `PATH`, give the absolute path to the executable instead — and **quote it if it contains a space**, because the failure is silent. Measured on 2026-09-18 with the path `/Users/…/GitHub Repos/robot-council-cli/robot-council`: unquoted, `claude mcp add` exits 0 and prints `Added stdio MCP server … with command: <the whole path> mcp`, which reads as correct, while what it wrote was `"command": "/Users/…/GitHub"` with `"args": ["Repos/robot-council-cli/robot-council", "mcp"]`. The success message rejoins the split with spaces, so the only place the damage is visible is `~/.claude.json`.

### Cursor

```json
{
  "mcpServers": {
    "robot-council": {
      "command": "robot-council",
      "args": ["mcp"],
      "env": {
        "ROBOT_COUNCIL_SERVICE": "https://your-fleet.example.com"
      }
    }
  }
}
```

Add `"ROBOT_COUNCIL_HARNESS": "cursor"` to that `env` block. **Here the recommendation is stronger than for Claude Code**: Cursor is detected by `CURSOR_AGENT`, a variable documented for its terminal agent, and whether the editor exports it to MCP stdio children has not been measured. Naming it removes the question.

**Verified 2026-09-21**, on Cursor 3.7.21 and Windows 11 Pro 25H2 (build 26200.8875), against a live fleet: the block above (without a `type` field) is the shape that launched. Cursor's [MCP docs](https://cursor.com/docs/mcp) put `command`, `args`, and `env` under `mcpServers.<id>` for stdio servers, and name `~/.cursor/mcp.json` (global) and `.cursor/mcp.json` (project) as the config locations. The same page's STDIO field table lists `type: "stdio"` as required while its examples omit it; the run that connected omitted it.

After enroll with `--harness=cursor`, Settings, then Tools & MCP, showed the server connected. This run was measured before [#127](https://github.com/robot-council/cli/issues/127), when launching joined the fleet; today the session below starts only once an agent calls `join`, or at launch with `"args": ["mcp", "--auto-join"]`. Reloading it there closed the old stdio process and started a new one: the fleet's agents list marked the previous session `gone` and showed a new `active` session for the same machine, harness `cursor`, last seen within seconds. That is the control that matters — the dashboard reading changes with the reload, so the variable reached the process and a session started on the service.

Put the entry in the **global** file when the fleet URL is yours. A project `.cursor/mcp.json` is shared with whoever opens that checkout; this repository does not ship one, because the URL is per deployment rather than part of the CLI source.

If `robot-council` is not on the `PATH` Cursor inherits, point `command` at `php` (absolute path to the binary) and put the absolute path to the launcher -- `bin\robot-council.php` in a versioned install -- then `mcp`, in `args`. Measured on the same Windows run: without that, Windows offered "Select an app to open `robot-council`" instead of launching the bridge.

**On macOS, the launcher needs `php` on that `PATH` too,** because the shim ends in `exec php`. Where `php` comes from a user-level install such as Laravel Herd (`~/Library/Application Support/Herd/bin`), a Cursor started from the Dock may not inherit the `PATH` your shell sets. The Cursor seat added on 2026-09-25 put `"PATH"` in this block's `env`, and set it in its stop-hook script, as a precaution ([#308](https://github.com/robot-council/cli/issues/308)). **Whether that was needed was not measured.** Pointing `command` at the absolute path to `php`, as on Windows, avoids the question.

### Codex

```toml
[mcp_servers.robot-council]
command = "robot-council"
args = ["mcp"]
env = { ROBOT_COUNCIL_SERVICE = "https://your-fleet.example.com", ROBOT_COUNCIL_HARNESS = "codex" }
```

The bridge this launches joins the fleet only when an agent calls `join`; add `"--auto-join"` to `args` to join at launch, as [joining the fleet](#joining-the-fleet) describes.

**Not run.** Codex is not installed on the machine this was written on, so nothing above launched a bridge. What *was* checked, on 2026-09-18, is the spelling: `command`, `args`, and `env` are the key names OpenAI's [configuration reference](https://learn.chatgpt.com/docs/config-file/config-reference) gives under `mcp_servers.<id>`. That reference carries no combined example, so the arrangement of those keys into the block above is this project's, and the claim here is about three key names and the table they sit under, not about a working setup. Send a correction if it does not launch.

### Solo

**Not run, and nothing about it was checked.** Solo is not installed on the machine this was written on. The expectation recorded in [#6](https://github.com/robot-council/cli/issues/6) is that it is configured through whichever harness it launches, which would make the Claude Code section above the whole of it — but that is an expectation, not a measurement, and no Solo documentation was read to support it.

### Naming a project

`--project` is a **label on a session**, not a filesystem path. It does not decide which credential is used — that is the harness — and the service never resolves it to anything. Its whole job is to let a fleet tell one checkout's sessions from another's.

**Write it as `<org>/<repo>/<worktree>`:**

```
UAMS-Web/uams-statamic/a
UAMS-Web/wordpress-importer/ci
```

Leave the last segment off for a checkout that is not a worktree.

#### The label is now two fields, and both are read from the checkout

The fleet stores **a repository and a work location** as separate fields rather than one label a reader has to parse, and the bridge proposes both from the checkout it is already running in. In an ordinary checkout you need neither flag:

| field | read from | example |
| --- | --- | --- |
| repository | the `origin` remote, reduced to `owner/name` | `UAMS-Web/uams-statamic` |
| work location | the git worktree's own name, or `primary` for the main checkout | `a`, `ci`, `primary` |

Override either with `--repository` or `--work-location`, independently — naming one does not stop the other being read. A written value is judged by exactly the rules a read one is, so writing it out is not a way to send something the fleet will refuse.

**A read value is a proposal, not a fact.** Neither field decides anything: they stay client-supplied, exactly as `--project` always has, and neither reaches an authorization decision. What changes is that they are right by default rather than right by diligence — a label typed once per worktree and never revisited is the kind that is wrong for months, because nothing ever contradicts it.

**`--project` still works and is still sent.** The fleet splits it into the same two fields when a client names *neither*, so nothing needs changing to keep working.

#### Why it was written out, and what reading it answers

A folder name is the obvious source and the wrong one, for three reasons, all of them measurable. Reading the **remote** rather than the folder answers two of them, and the third is why a read value can still be overridden:

- **A folder name loses the organization.** `uams-statamic` alone is not a repository; two organizations can both have one. *Answered:* `origin` carries `owner/name`.
- **A path does not fit.** `ProjectId` accepts `[A-Za-z0-9._/-]` — forward slash is in it, **backslash is not** — so `C:\Users\Josh\Herd\uams-statamic-a` is refused by the service outright. A configuration that interpolates `${workspaceFolder}` fails at the far end, mid-wire-up, rather than where it was written. *Answered:* nothing derived is ever a path.
- **A worktree is whatever that machine called the directory.** A worktree at `uams-statamic-a` on one machine and `statamic-a` on another reports two places for one thing, which defeats the comparison the label exists for. **Not answered, and not answerable by any derivation** — which is why the work location is a proposal and `--work-location` exists. If the fleet wants short, comparable labels, that is a naming convention to agree once and apply everywhere.

One more for anyone tempted to derive it with a script: worktrees are often **siblings** of the primary checkout, so `…\uams-statamic` is a string prefix of `…\uams-statamic-a`. A prefix comparison matches the wrong one; anchor on the separator.

#### The length bound, and what to do when a name is long

`ProjectId` allows **128 characters**. The shape above fits comfortably for ordinary names — the examples are 24 and 30 — but GitHub permits a 39-character organization and a 100-character repository, and those do not fit. Measured on 2026-09-21:

| value | length | verdict |
| --- | --- | --- |
| `UAMS-Web/uams-statamic/a` | 24 | accepted |
| 39-char org, 85-char repo, 2-char worktree | 128 | accepted |
| 39-char org, 86-char repo, 2-char worktree | 129 | **refused** |
| 39-char org, 100-char repo, worktree | 142 | **refused** |

For a repository whose full name does not fit, drop the organization and use `<repo>/<worktree>`. Be consistent across machines: the label is only useful if every machine writes the same one for the same checkout.

#### Where it goes

After `mcp`, as an argument to the bridge rather than an environment variable — one harness config serves one checkout, so it belongs beside the command.

**Verified 2026-09-21** on Claude Code 2.1.236 against a live fleet:

```bash
claude mcp add robot-council \
  -e ROBOT_COUNCIL_SERVICE=https://your-fleet.example.com \
  -e ROBOT_COUNCIL_HARNESS=claude \
  -- robot-council mcp --project=UAMS-Web/uams-statamic/a
```

That wrote `"args": ["mcp", "--project=UAMS-Web/uams-statamic/a"]` and `claude mcp list` reported `Connected`.

For Cursor and Codex the flag goes in the same place — the `args` array, after `"mcp"`. **Not run for either**: neither harness was launched with a `--project` on the machine this was written on, and the existing sections say which harnesses have been run and which have not.

### When the bridge refuses

The bridge presents one harness's credential, so it will not join without knowing which harness it is. Three refusals, each naming what to do. Since [#127](https://github.com/robot-council/cli/issues/127) they arrive when the fleet is joined rather than at launch: as the `join` tool's result, after `Could not join the fleet:`, and on stderr for `--auto-join`. The bridge stays up either way.

```
robot-council: Could not tell which harness this is, and a credential belongs to one.
Enrolled here: cursor, claude. Pass --harness=<name>, or set ROBOT_COUNCIL_HARNESS
in this harness's configuration.
```

Nothing named a harness and detection did not fire. The list is what this machine has enrolled, in the order the detector enumerates harnesses rather than the order they were enrolled.

```
robot-council: This machine is not enrolled as `cursor` against that fleet.
Enrolled here: claude. Pass --harness=<name>, or set ROBOT_COUNCIL_HARNESS
in this harness's configuration.
```

A harness was named and has no credential — a typo, or a harness not enrolled yet.

```
robot-council: Could not tell which harness this is, and a credential belongs to one.
A credential stored before harnesses were told apart is here. Pass
--harness=<the harness it was enrolled as> to claim it, or run `robot-council enroll` again.
```

**The upgrade case, and it needs one action per machine, once.** A machine enrolled before credentials were kept per harness has one filed under the fleet alone. It is not adopted automatically, because it belongs to whichever harness enrolled it and nothing here can know which — handing it to whoever asks first is the guess this design refuses. Naming the harness claims it, moving it under the harness's own key. **Nothing is lost and no re-enrollment is required.**

All three were produced by running the bridge on 2026-09-21, when it still joined at launch, rather than read off the source, which is why the second line of the first one says `cursor, claude`. All three go to stderr, prefixed `robot-council:`. `mcp`'s stdout is a protocol stream a harness parses, so nothing else ever goes there.

**The list of enrolled harnesses is a lower bound**, for two reasons. It is built by asking about each harness `laravel/agent-detector` knows, because no credential store can list its keys, so a harness named by hand that the detector has never heard of will not appear in it. And a harness whose credential cannot be read — a credential store that has broken since the command started — is reported as not enrolled rather than aborting the refusal, so the list stays short rather than being replaced by a different error.

### Any other harness

Anything that speaks MCP over stdio works: the server command is `robot-council mcp` and the one environment variable is `ROBOT_COUNCIL_SERVICE`. The bridge writes protocol to stdout and diagnostics to stderr, and never mixes them.

## Handing fleet events to an agent at a turn boundary

The bridge follows the fleet's change feed while an agent works and leaves whatever concerns that
session in a sink. `robot-council pending` prints what is waiting and clears it, and a harness's
**stop hook** is what calls it -- so the moment an agent would otherwise go idle, it picks up the
directive or the handed-back task instead.

**The hook delivers; it does not wake.** On its own it runs only when a turn ends, so an agent that
stopped an hour ago stays stopped until something starts a turn. **In Claude Code, the bridge can
start one**: when events arrive for an idle session, it sends a channel notice, and the turn that
notice starts ends by running this hook (since `v0.4.0`,
[#197](https://github.com/robot-council/cli/pull/197)). That needs Claude Code started with
`--dangerously-load-development-channels server:robot-council`, and without it nothing reports that
the seat cannot be woken. The wiki's
[Starting a seat so it can be woken](https://github.com/robot-council/cli/wiki/Starting-a-seat-so-it-can-be-woken)
has the invocation, its requirements, and how to confirm it registered. Cursor and Codex have no such
notice, so for them the hook delivers at the next turn the operator starts.

**Without a stop hook, a woken Claude Code agent is told to read the feed itself**
([#306](https://github.com/robot-council/cli/issues/306)). The notice only wakes the agent, and
the hook is what delivers. On 2026-09-25, six seats with channels on and no `Stop` hook each woke
within a second of a placement, ended the turn as instructed, and saw nothing for over forty minutes.
So under Claude Code, the bridge checks at start for a `Stop` hook in the settings Claude Code reads
for the launch folder: `settings.json` and `settings.local.json` in `~/.claude` (or
`CLAUDE_CONFIG_DIR`), and in the project's `.claude`. A hook counts when its `type` is `command`,
whatever it runs. `disableAllHooks` is taken from the most specific file that sets it, as Claude
Code does. Managed (enterprise) settings are not read.

If no hook will run, a notice tells the agent to call `events_read` and act on what concerns it,
rather than to end the turn. The bridge stops repeating that notice once the agent has read the
feed, since with no hook nothing else would ever settle it. Either way, the bridge says which case
applied once, on stderr, which Claude Code keeps as the server's log:
`robot-council: stop hook: found in <file>.` or
`robot-council: stop hook: none found; an agent woken by a notice is told to read the feed itself.`
A settings file that is not valid JSON is named there and treated as having no hook. Reading the
feed that way works, but the hook is still worth installing, because it hands over events at every
turn end and not only when a notice wakes the agent.

**A hook that always continues the turn is a session that never stops.** `robot-council pending`
prints nothing and exits 0 when the fleet has been quiet, and the script below ends the turn on empty
output. That one line is the whole difference between a hook and a loop.

**Without a session in the `coordinator` role, almost nothing arrives.** A directive is the only
event that reaches a waiting session whatever it concerns -- every session when it names no
`targets`, and only the sessions it names when it does
([#269](https://github.com/robot-council/cli/issues/269)) -- and posting one needs
`coordinator:direct`, which enrollment can never ask for and which comes with the `coordinator` role
an administrator gives a running session from the fleet's administration page. Reassigning a task,
cancelling one, and forcing a lock open need it too. What still arrives without one: a lock taken
over from this session, which needs only `locks:acquire`, and this session's own `stale` marking. A
task claim always assigns to whoever claimed it. Narration reaches a sink only when it is addressed
to that session, through `meta.to` or a task it holds in `meta.to_tasks`, which is how a coordinator
answers a seat ([#313](https://github.com/robot-council/cli/issues/313)); any other narration
reaches no sink at all. Decided on [#112](https://github.com/robot-council/cli/issues/112), where
the alternatives are recorded.

So a machine can be enrolled, its bridge following the feed, its sink written and its stop hook
wired into every harness, and still receive no directive at all -- because no session on that fleet
is running in the `coordinator` role. **Every part reports healthy and the symptom is silence**,
which is indistinguishable from a fleet that genuinely has nothing to say. Wiring the hook up is
worth doing anyway; it costs nothing while the fleet is quiet. Just know which of the two you are
looking at.

**A session does not need the ability to receive.** Holding none of it is the ordinary, correct
state for a machine that only listens, and it is what enrollment grants. The ability is needed by
whoever sends.

### Keeping an idle Claude Code session's cache warm

An idle session's prompt cache expires, and the first turn after that pays to write the whole conversation again. For a fleet session idle between tasks, that cost lands on the first turn of the next task. **`--keep-warm=<minutes>` has the bridge wake its own session once it has been idle that long**, with a channel notice saying there is nothing to do, so the woken turn is short and hits the cache ([#279](https://github.com/robot-council/cli/issues/279)). Off by default.

```bash
claude mcp add -s user robot-council \
  -e ROBOT_COUNCIL_SERVICE=https://your-fleet.example.com \
  -e ROBOT_COUNCIL_HARNESS=claude \
  -- robot-council mcp --keep-warm=55 --keep-warm-for=480
```

**Set the interval from your cache's TTL; the bridge does not detect it.** Claude Code's [prompt caching documentation](https://code.claude.com/docs/en/prompt-caching.md) gives the main conversation a **one-hour** TTL on a Claude subscription within its included usage, and **five minutes** on an API key, a cloud provider, or a subscription drawing on usage credits; `CLAUDE_CODE_PROMPT_CACHE_TTL` sets it to `5m` or `1h`. For one hour, 55 minutes leaves room for a slow turn. **On a five-minute TTL the option buys nothing at any practical interval.** It would take a woken turn every four minutes for as long as the session sits idle, and by [the pricing](https://platform.claude.com/docs/en/about-claude/pricing) each reads the whole cache at a tenth of the input price, so an idle hour of them costs about what the one rewrite they prevent does, before counting what each woken turn writes.

**`--keep-warm-for=<minutes>` is the ceiling.** Once the session has been idle that long, keep-alives stop, so a seat abandoned overnight is not woken every hour until morning. Without it they go on for as long as the bridge runs. It has to be longer than the interval, or no keep-alive could ever be sent. A value that is not a whole number of minutes, a ceiling no longer than the interval, or `--keep-warm` given to any harness but Claude Code is reported on stderr, and the bridge runs without keep-alives.

**What counts as activity, and what the bridge cannot see.** The bridge sees no turns ([#211](https://github.com/robot-council/cli/issues/211) records why), so it reads three things that stand in for them: a tool call arriving, a notice it sent about fleet events, and a turn end the stop hook recorded. Under Claude Code, `robot-council pending` records the time it drains the sink, in a file beside the sink; `--peek` does not, and neither does any other harness's hook. So:

- **It needs the stop hook below, and Claude Code started with channels**, as [waking a seat](https://github.com/robot-council/cli/wiki/Starting-a-seat-so-it-can-be-woken) describes. Without channels, the notice is dropped and nothing is kept warm.
- **A long turn that calls no fleet tool looks idle until it ends**, hook or no hook: running Bash and editing files moves nothing the bridge reads. So a turn longer than the interval can receive a keep-alive while it runs. The server instructions tell the agent to ignore one that arrives mid-task and carry on, and the turn folds it in. Without the hook, every turn is like this.
- **A keep-alive's own turn end is not activity**, or the ceiling could never arrive: the first turn end within two minutes of a keep-alive is taken as that keep-alive's. A tool call in between means the agent did something, and the turn end after it counts. The cost is the case above: a real turn that folded a keep-alive in, and ended within two minutes of it without calling a fleet tool, is taken for the keep-alive's, and the ceiling arrives that much early.
- **A continued turn's end is not recorded.** The script's loop guard exits before `pending` runs when Claude Code has already continued the turn, so the clock runs from the first end, and a long continuation can be followed by a keep-alive sooner than the interval.
- **The record has the sink's identity**: the service, the harness, and the project or the checkout. Two Claude Code sessions in one checkout share one record, as they share one sink.

**What the woken turn does has not been measured yet.** The server instructions tell the agent that a notice carrying `keep_warm` has nothing behind it, and to end the turn without tool calls unless it is in the middle of a task; whether a live session does, and that the cache stays warm, are open on [#279](https://github.com/robot-council/cli/issues/279).

### The script every harness runs

One script serves all three harnesses. Fill in the two values at the top and give the path to
whichever configuration below matches the harness.

The script is [`resources/stop-hook/robot-council-stop-hook`](resources/stop-hook/robot-council-stop-hook), committed and tested by `tests/Feature/StopHookScriptTest.php` rather than printed here, so the copy you download is the copy the suite runs ([#318](https://github.com/robot-council/cli/issues/318)). Download it:

```bash
curl -fsSL -o ~/bin/robot-council-stop-hook \
  https://raw.githubusercontent.com/robot-council/cli/main/resources/stop-hook/robot-council-stop-hook
chmod +x ~/bin/robot-council-stop-hook
```

From the first release that carries it, a Composer install has it too, at `resources/stop-hook/robot-council-stop-hook` inside the installed package.

Put it where the harness can run it.
`robot-council` has to be on the `PATH` the harness hands the hook, or the script needs an absolute
path in place of it -- and that path needs quoting if it contains a space, for the reason the Claude
Code MCP section above records.

**The two values live in the script rather than in the harness configuration**, and that is not
arbitrary. `exit 2` makes Claude Code print the entire hook command string into the transcript
(measured below), so anything put there is one misconfigured hook away from being transcript
content. The script is also the only one of the two places that all three harnesses can reach:
Cursor's `stop` entry has no `env` key at all, and the MCP block's `env` configures the bridge's
process rather than the hook's.

**The sink is keyed by the service, the harness, and the project, and, when no project is named, by
the checkout** ([#299](https://github.com/robot-council/cli/issues/299)). A hook that resolves any
of them differently from the bridge beside it reads an empty sink and reports a quiet fleet.
Uncomment `ROBOT_COUNCIL_PROJECT` wherever the bridge was given `--project`.

**The checkout is the git directory of the folder the session was launched in**, or that folder
itself outside a repository. It is what keeps sessions in different checkouts apart: before it,
every session launched from one user-level configuration shared one sink, and the first stop hook to
run took every session's events. Each worktree has its own git directory, so each slot gets its own
sink. **Two sessions in one checkout still share a sink**, so run one seat per checkout
([#300](https://github.com/robot-council/cli/issues/300) decided that, and keeps the research
into separating them open). **A second bridge started in a checkout that already has one says
so** ([#320](https://github.com/robot-council/cli/issues/320)): it keeps running, since an operator
may be mid-handover, but writes `robot-council: another bridge is running in this checkout (pid N)`
to stderr and repeats the warning in what the agent reads at connect and in the `join` result, so
the agent can tell its operator. A bridge given `--project` checks that project instead, so two
checkouts sharing one project warn too. The check is an operating-system lock on the sink, released
when the bridge exits however it exits, so a killed bridge leaves nothing behind to warn about.

**The bridge and the hook have to agree on that folder, and they do not start in the same one.**
Measured on Claude Code 2.1.282: the MCP server runs in the folder Claude Code was launched from,
but a `Stop` hook runs in the session's current folder, which an agent's `cd` moves. After a `cd`
into an `--add-dir` repository, the hook ran in that repository. What stays put is
`CLAUDE_PROJECT_DIR`: set for the hook, it still named the launch folder. So `robot-council pending`
resolves the checkout from `CLAUDE_PROJECT_DIR` when it is set, and from its own folder otherwise.
The MCP server receives it too (an earlier note here said it did not; that probe was wrong, because
Claude Code expands `${VAR}` in `.mcp.json` itself), and needs none, since it stays where it started.

**Cursor's sink has no checkout in its key** ([#327](https://github.com/robot-council/cli/issues/327)).
Measured on Cursor 3.17.19: Cursor runs one bridge for the whole application, in the home folder,
and runs its stop hook in `~/.cursor`, so neither side can see the project, and from v0.4.23 to the
fix the two keys never matched and a Cursor seat received nothing. One bridge per application means
one sink per application is the right match. **Codex is unmeasured**, and keeps the checkout key.

**Upgrading past this drops events already waiting under the old shared key.** A seat without
`--project` starts reading its checkout's sink, and nothing reads the old shared one again. Those
events are not delivered again. They are the ones no session had read, often addressed to a session
that has since ended.

**The script fails open, on purpose and invisibly.** `|| exit 0` means a missing `robot-council`, an
unset `ROBOT_COUNCIL_SERVICE`, or a harness it cannot name all end the turn normally rather than
blocking it -- the right direction, because a broken hook must not trap an agent in a turn it cannot
finish. The cost is that every one of those looks exactly like a fleet with nothing to say. That is
what the verification below is for, and it is worth running once per machine rather than trusting the
silence.

### Claude Code

In `.claude/settings.json`, at project or user level:

```json
{
  "hooks": {
    "Stop": [
      {
        "hooks": [
          {
            "type": "command",
            "command": "/absolute/path/to/robot-council-stop-hook",
            "timeout": 20
          }
        ]
      }
    ]
  }
}
```

`~/bin/robot-council-stop-hook` works too: a `command` with no `args` array runs through a shell, and
a probe writing `printf '%s' ~` to a file produced the home directory. That was measured for Claude
Code. For Cursor on macOS the same shell behavior was measured below, though not with `~` leading the
command word itself. **Codex was not measured**, and an unexpanded `~` is a directory that does not
exist rather than an error anyone sees, so write the path out in full unless you are going to check.

**Cursor's `command` runs through `/bin/bash` on macOS**, measured 2026-09-24 with the same probe
and recorded in the Cursor section below: `~` expanded to the home directory, and an environment
variable prefixed onto `command` reached the script. **On Windows it is still open.** There the entry has to
point at a `.cmd` rather than at a bash script, so the run there used an absolute path and no `~`
ever appeared; Cursor's execution log names the mechanism `windows_temp_file`, which tells you it
writes a temporary script but not whether a shell would have expanded anything in it. Do not carry
the macOS answer across.

**Verified 2026-09-22**, on Claude Code 2.1.236 and macOS 26.6.2. Three runs of
`claude -p 'Say the single word READY and stop.'` in a throwaway project, differing only in whether
the sink held anything and whether the hook was configured. The script was the one above with its
two placeholders filled in and two extra lines pointing `PATH` and `XDG_STATE_HOME` at the throwaway
install; the configuration was the block above with the absolute path:

| sink | hook | `num_turns` | the directive in the transcript | sink |
| --- | --- | --- | --- | --- |
| one directive | configured | **2** | **present**, verbatim | 229 B to 0 B |
| empty | configured | 1 | absent | empty throughout |
| one directive | **removed** | 1 | absent | 229 B to **229 B** |

**The third row is the control**, and it is what makes the first mean anything: same planted sink,
hook taken out, turn ended at one, nothing in the transcript, and the sink was 229 bytes on both
sides. A hook that never fired and a fleet with nothing to say are otherwise the same reading.

All three runs used `--output-format stream-json`, so *absent from the transcript* is an observation
rather than an inference from `num_turns`.

Stop hooks do fire under `claude -p`, and a blocked stop does produce another turn there. Claude
Code's hooks documentation does not say either way for print mode, so both halves of that sentence
are measurements rather than restatements.

What the agent receives is a user message, quoted verbatim from the first run:

```
Stop hook feedback:
The fleet has news:
[directive] PROBE-JULIET the release branch is cut; hold new migrations from otherdev at 2026-09-22T16:40:00+00:00
```

The loop guard was measured on the script alone rather than through a session, by handing it
`{"stop_hook_active":true}`: it printed nothing, exited 0, and left the sink at 187 bytes on both
sides. **It is not what ended the first run at two turns** -- the drain had already emptied the sink,
so the hook's second firing had nothing to say either way. The guard is what covers the case the
drain does not: the bridge's follower writing *new* events between the first firing and the second,
which would otherwise continue the turn again, and again for as long as the fleet keeps talking.

**The agent weighed the directive rather than obeying it, in every run.** A probe directive asking
for an exact token back was refused, with the session naming the sender label as self-asserted and
unverified. That is the behavior [#14](https://github.com/robot-council/core/issues/14)'s threat
model asks for -- event content is untrusted input to something that may have shell access -- and it
is why `pending` prints who said it. Bound worth stating: those sessions had no bridge wired in and
no project instructions, so they met the directive as a stranger's words rather than as fleet
coordination. How an agent that *is* on the fleet weighs one has not been measured.

#### The shape that drains the sink and throws it away

Claude Code's hook reference documents decision control through
`hookSpecificOutput.permissionDecision`. **For `Stop` that is silently ignored**, and the cost is not
a failed injection -- it is lost events.

Three runs on 2026-09-22, same build, same loop, differing only in what the hook printed, each with a
freshly planted sink:

| hook stdout | `num_turns` | reached the transcript | sink |
| --- | --- | --- | --- |
| `hookSpecificOutput.permissionDecision: "deny"` | 1 | no | 187 B to **0 B** |
| `hookSpecificOutput.permissionDecision: "block"` | 1 | no | 188 B to **0 B** |
| top-level `{"decision":"block","reason":"..."}` | **2** | **yes** | 188 B to 0 B |

**Both spellings the reference suggests were tried**, and the third row is the positive control that
makes the first two an absence rather than a broken probe: same harness, same loop, same planted
sink, injecting correctly.

The sink is emptied either way, and that is the whole problem. `robot-council pending` drains on
read, so by the time the harness discards the hook's output the events are already gone. Nothing
errors, nothing is logged, and every surface afterwards says the fleet had been quiet.

Top-level `{"decision":"block","reason":"..."}` is the shape that works, and it is what the script
prints.

`exit 2` with the text on stderr also continues the turn, and is the wrong choice here for a
measurable reason: Claude Code prefixes the injected message with **the entire hook command string**
in square brackets. Measured on the same day, a hook whose command carried its configuration in
environment assignments put every one of them into the transcript. That is the leak this project
already refuses when it keeps the credential out of harness configuration, arriving through a
different door, and it is why the script above holds its own values instead.

#### Verifying it once, on the machine it is installed on

A hook that never fired and a fleet with nothing to say read the same, so this needs the control
rather than the happy path. Run it with something genuinely waiting -- a teammate posting a directive
is the ordinary way to get there.

**`--peek` needs the same two values the hook has**, because they live in the script and not in your
shell, and it exits 1 without them while printing nothing on stdout. Read `$?`, or an unconfigured
command and an empty sink are the same reading:

```bash
export ROBOT_COUNCIL_SERVICE=https://your-fleet.example.com
export ROBOT_COUNCIL_HARNESS=claude

robot-council pending --peek; echo "rc=$?"     # rc=1 means it could not look, not that nothing waits
claude -p 'Say READY and stop.' --output-format json < /dev/null | jq '.num_turns'
robot-council pending --peek; echo "rc=$?"     # and whether it survived
```

**`--peek` is read-only**, and safe to run beside a live bridge. It takes a shared lock and reads;
it never truncates and never writes. Until
[#71](https://github.com/robot-council/cli/issues/71) it drained the sink and wrote it back, so an
event the bridge appended in that window could be ordered behind older ones or, at the bound,
dropped.

Read `num_turns` and the sink together, because each failure looks like success on its own:

| `num_turns` | sink afterward | what happened |
| --- | --- | --- |
| 2 | empty | the hook fired and the harness accepted its output |
| 1 | **empty** | the hook ran and its output was discarded: the wrong JSON shape, `php` missing, or the `timeout` firing after the drain |
| 1 | still full | the drain never happened: the hook never ran, or it ran and failed open |

**The third row has more causes than a missing file**, and they are the ones the script is built to
fail open on: `robot-council` not on the hook's `PATH`, an unset `ROBOT_COUNCIL_SERVICE`, a harness
it cannot name. A `ROBOT_COUNCIL_PROJECT` that disagrees with the bridge's `--project` also lands
here, because the drain empties a *different* sink and leaves this one untouched. Check those before
checking `chmod +x`.

Then run it again with nothing waiting. `num_turns` of 1 there is the turn ending normally, which is
what stops the hook from becoming a loop.

### Cursor

In `~/.cursor/hooks.json` (global) or `.cursor/hooks.json` (project):

```json
{
  "version": 1,
  "hooks": {
    "stop": [
      {
        "command": "/absolute/path/to/robot-council-stop-hook",
        "loop_limit": 10
      }
    ]
  }
}
```

Set `ROBOT_COUNCIL_HARNESS=cursor` in the script. Cursor returns `followup_message`, which its
documentation describes as submitted automatically as the next user message, and it caps
continuations with `loop_limit` rather than with a field on the payload -- which is why the script's
own `stop_hook_active` guard never fires here and `loop_limit` is the only thing bounding it.

**Verified 2026-09-22**, on Cursor 3.7.21 and Windows 11 Pro 26200, against a live fleet, by driving
the editor's own agent rather than a headless runner -- there is none on Windows, for the reason
below. Three turns of `say hello and stop`, differing only in whether the sink held anything and
whether the hook was configured:

| sink | hook | hook executions | the directive in the conversation | sink |
| --- | --- | --- | --- | --- |
| one directive | configured | **2** | **present**, verbatim | 182 B to 0 B |
| empty | configured | 1 | absent | empty throughout |
| one directive | **removed** | 0 | absent | 182 B to **182 B** |

**The third row is the control**, and it is what makes the first mean anything: same planted sink,
`hooks.json` moved aside, nothing injected, and the sink was 182 bytes on both sides.

The first two rows come from **one** turn rather than two. The hook fired, drained the sink and
continued the turn; the agent worked on what arrived; and when it stopped again the hook fired a
second time against the now-empty sink, printed nothing, and let the turn end. Cursor's stop payload
carries `loop_count`, and the two firings arrived as `0` and `1`.

**Cursor adds no wrapper.** What arrives is a user message carrying the script's bytes and nothing
else, quoted verbatim from the first run:

```
The fleet has news:
[directive] cli#72 probe: confirm Cursor submits followup_message as the next user message. from an unnamed session
```

`The fleet has news:` is the script's own line. Claude Code prefixes its equivalent with
`Stop hook feedback:`; Cursor prefixes nothing, so whatever the hook prints is the whole message.

**The stop payload, as it actually arrives** -- previously taken from Cursor's documentation:

```json
{"conversation_id":"…","generation_id":"…","model":"default","status":"completed",
 "loop_count":0,"input_tokens":21434,"output_tokens":27,"cache_read_tokens":5504,
 "cache_write_tokens":0,"session_id":"…","hook_event_name":"stop","cursor_version":"3.7.21",
 "workspace_roots":["/d:/GitHub Repos/robot-council/cli"],"user_email":"…","transcript_path":"…"}
```

`stop_hook_active` is absent, which is why the script's own loop guard never fires here and
`loop_limit` is the only thing bounding it -- now measured rather than read from the documentation.

**The payload arrives with a UTF-8 BOM.** Measured: the first three bytes on stdin are `ef bb bf`,
before the `{`. What that costs depends on the parser, and the obvious guess is wrong. Measured on
macOS 26.6.2, each reading controlled against the same document without a BOM:

| parser | a BOMed payload |
| --- | --- |
| `jq` 1.7.1 | parsed, correct value. `src/jv_parse.c` has skipped a leading BOM since at least the `jq-1.6` tag |
| PHP 8.4.23 `json_decode` | refused -- `Syntax error`, which does not mention a BOM |
| Python 3.9.6 `json.loads` | refused -- `Unexpected UTF-8 BOM (decode using utf-8-sig)` |

All three still refuse genuinely malformed input, so the table records what the parsers do rather
than an instrument that cannot tell the two apart.

**`php` is the row that matters here**, because this script already invokes it to encode the reply,
so it is the nearest tool to hand for anyone who replaces the substring matching with parsing -- and
its message names a syntax error rather than the BOM. By this script's own design a payload it
cannot read ends the turn with the sink untouched, so that failure reads exactly like a quiet fleet.
The script strips the BOM at the top for that reason; the matching would survive without it.

**Claude Code's payload carries no BOM**, measured on 2.1.236: the first bytes on stdin are
`{"session_id"`. **Codex's is unmeasured**, because it is installed on no machine this project is
worked from -- which is not the same as having looked.

**On Windows the `command` is a path to a `.cmd`, not to the bash script.** Cursor's execution log
names the mechanism `windows_temp_file`: it writes the command to a temporary script and runs that.
A bash script is not directly executable on Windows whatever the shell answer turns out to be, so
the entry points at a two-line `.cmd` that invokes Git Bash on the hook:

```json
{
  "version": 1,
  "hooks": {
    "stop": [
      {
        "command": "C:\\Users\\<you>\\bin\\robot-council-stop-hook.cmd",
        "loop_limit": 10
      }
    ]
  }
}
```

```bat
@echo off
"C:\Program Files\Git\bin\bash.exe" "%USERPROFILE%\bin\robot-council-stop-hook"
```

stdin passes through the shim untouched. **Whether Cursor on Windows would have interpreted a shell
line directly is still unmeasured**, and the shim is deliberately the arrangement that does not
depend on the answer. The macOS answer, below, is not evidence for Windows: the mechanism there is a
temporary script, and what interprets that script was not observed.

**The two environment variables reach the hook by being written into the script**, which is what the
section above recommends and what was run on Windows. A `stop` entry has no `env` key. Prefixing
them onto `command` works on macOS (measured below), but the recommendation stays with the script:
it is the one arrangement that works on every harness and platform this README covers, and the
Windows answer is not known.

**Verified 2026-09-24: on macOS a `stop` hook's `command` is shell-interpreted.** Cursor 3.17.19 on
macOS 26.6.2, driving the editor's own agent with one turn of `Say the single word READY and stop.`
in a throwaway workspace whose `.cursor/hooks.json` held four `stop` entries (`<dir>` stands for an
absolute path with no spaces):

```json
{
  "version": 1,
  "hooks": {
    "stop": [
      { "command": "<dir>/probe-control.sh" },
      { "command": "printf '%s' ~ > <dir>/out/tilde.out" },
      { "command": "FOO=bar <dir>/probe-env.sh" },
      { "command": "<dir>/probe-argv.sh ~ '$HOME' \"two words\"" }
    ]
  }
}
```

`probe-env.sh` records `FOO=${FOO-} set=${FOO+yes}`, which tells an unset variable from an empty one;
`probe-argv.sh` records `$#` and each argument in brackets. Before Cursor ran them, the argument
probe was run both through `sh -c` and as a whitespace-split exec, so that the two possible answers
were known to look different:

| probe | through `sh -c` | whitespace-split, no shell | Cursor's `stop` |
| --- | --- | --- | --- |
| `argv.out` | `argc=3` `[/Users/<you>]` `[$HOME]` `[two words]` | `argc=4` `[~]` `[$HOME]` `["two]` `[words"]` | `argc=3` `[/Users/<you>]` `[$HOME]` `[two words]` |
| `tilde.out` | not run | not run | `/Users/<you>` |
| `env.out` | `FOO=bar set=yes` | not run | `FOO=bar set=yes` |

`tilde.out` existing at all means `>` was honored as a redirect: without a shell, `>` and the path
would have reached `printf` as arguments and no file would have been written. `cursor.hooks.*.log`
recorded `Found 4 hook(s) to execute for step: stop` and four `exit code: 0`, and the control wrote
its timestamp once.

A second editor turn, in a new chat, named the interpreter and took the baseline for `FOO`. Its
`stop` entries were the control, `probe-env.sh` with **no** prefix, and

```json
{ "command": "echo \"zero=$0 comm=$(ps -o comm= -p $$) sub=$(echo ok)\" > <dir>/out/shell.out; echo seq=ok >> <dir>/out/shell.out" }
```

which wrote

```
zero=/bin/bash comm=/bin/bash sub=ok
seq=ok
```

against `zero=sh comm=sh sub=ok` and `seq=ok` from the same line under `sh -c`. The unprefixed
`probe-env.sh` wrote `FOO= set=`, so the `bar` in the first turn came from the prefix and not from
Cursor's own environment. **So on macOS Cursor hands a `stop` `command` to `/bin/bash`**: `~`
expands, quotes are removed, single quotes suppress `$`, command substitution and `;` work, and a
`VAR=value` prefix reaches the script. The log names no mechanism on macOS, where Windows names
`windows_temp_file`.

**`hooks.json` is picked up without restarting Cursor.** Measured: the file was written at
17:45:36 UTC and `cursor.hooks.*.log` recorded `Reloading hooks configuration...` at 17:45:37.777Z
and `Loaded 1 user hook(s) for steps: stop` at 17:45:38.019Z. Settings, then Hooks, shows the loaded
entry and an **Execution Log** -- which is the instrument to reach for, because it distinguishes a
hook that fired and said nothing from one that never fired at all.

**But it watches for writes, not for removal, and that one bit me.** Moving `hooks.json` aside to
disable the hook did nothing: Settings went on showing `Configured Hooks (1)`, and the hook went on
firing and draining the sink. Writing a config with an empty `hooks` object instead produced
`Reloading hooks configuration...` and `Loaded 0 user hook(s) for steps:` within a second, and the
entry disappeared from Settings. **Disable a Cursor hook by writing an empty configuration, not by
deleting the file** -- otherwise it keeps running while every visible sign says it is gone, which for
this hook means it keeps emptying the sink.

Two things in those logs are noise rather than a fault. `ERROR: Failed to parse project hooks
configuration` is followed immediately by `No project hooks configuration found`, and this
repository ships no `.cursor/hooks.json` -- Cursor logs an error for an absent project config. And
the log files report **0 bytes** while holding content, because the size is not flushed to the
directory entry while Cursor holds the handle; read them rather than measuring them.

**`robot-council` was not on `PATH` on the machine this was run on**, and that is worth stating
because of how it failed. With a bare `robot-council` the hook's `|| exit 0` turned
`command not found` into a turn that ended quietly -- byte-identical to a quiet fleet, with nothing
anywhere reporting it. The script here names the executable absolutely. Check the binary resolves
before trusting a quiet run.

Cursor 3.17.19 is installed on the machine this was written on, and **`cursor agent` is a headless
runner shaped like `claude -p`**: `-p/--print` with `--output-format text | json | stream-json`,
which is what the Claude Code measurements above were taken with. The shape is where the
resemblance ends for this hook, because `-p` does not run `stop` at all (below).

**`agent` is routed by the `cursor` launcher script, not by the Cursor binary, and invoking the
binary directly fails silently.** On macOS `/usr/local/bin/cursor` is a 142-line shell script whose
routing is three branches: `editor` and anything unrecognized go to the editor CLI, and `agent`
`exec`s `~/.local/bin/cursor-agent`, installing it from `cursor.com/install` first if it is missing
and enforcing a minimum version. The editor CLI treats arguments it does not recognize as **paths to
open**, so bypassing the script turns `cursor agent login` into two empty editor tabs named `agent`
and `login`, with no error.

**`cursor --help` does not discriminate**, which is what makes this worth writing down. Cursor 3.7.21
on Windows lists `agent` under `Subcommands` in the help printed by `cursor.exe`, and that same
invocation does not route it -- the binary advertises a subcommand the launcher implements. Both
3.7.21 and 3.17.19 list it; only one of the two invocations acted on it.

So **call `cursor-agent` directly** rather than through `cursor agent`, and install it from
`cursor.com/install` where it is missing. That is one binary with one behavior, instead of a
launcher whose presence decides what the same command line means. Invoking it installs
`cursor-agent` from `cursor.com/install` on first use; here that produced 2026.09.18-9a7762b in
`~/.local/bin`, which is not on the default `PATH`. `cursor agent status` then reported **`Not logged
in`**, and `cursor agent login` is a browser flow, so nothing was run on 2026-09-22. The runs dated
2026-09-24 below followed a browser login, made with the full path because `~/.local/bin` was still
not on `PATH`.

This is corrected from an earlier reading of "no headless binary", which came from looking for a
`cursor-agent` on `PATH` and reading the top of `cursor --help`. The `Subcommands` block naming
`agent` is at the bottom of that same output. A narrower question than the one that mattered,
answered in the reassuring direction.

**Under `cursor-agent -p`, no `stop` hook wrote its marker.** Measured 2026-09-24 with
`cursor-agent` 2026.09.18-9a7762b on macOS 26.6.2, logged in, running `cursor-agent -p --trust 'Say
the single word READY and stop.'` in a throwaway workspace. Three runs, each exiting 0 and printing
`READY`:

| run | project `.cursor/hooks.json` | markers written |
| --- | --- | --- |
| 1 | the four `stop` probes; the workspace not a git repository | none |
| 2 | the same, after `git init` | none |
| 3 | one marker command under each of `sessionStart`, `beforeSubmitPrompt`, `afterAgentResponse`, `stop` | `sessionStart` only |

Run 3's `sessionStart` line is the control, and only run 3 has it: it shows the project config was
read and a hook does execute under `-p`, so the silence from `stop` is not a config that never
loaded. `beforeSubmitPrompt` and `afterAgentResponse` were silent too, so `-p` skips more of the
agent loop than `stop` alone. What the markers cannot tell apart is a hook never started from one
started and killed as the process exited; no `cursor-agent` hook log was found to settle it. Either
way **`-p` did not exercise this hook**, and the measurement above drove the editor. Interactive
`cursor-agent`, without `-p`, was not tried.

The three recording probes above, moved under `sessionStart`, produced files byte-for-byte identical
to the editor's `stop` results in a fourth headless run -- `~` expanded, `FOO=bar` reached the
script, `argc=3`. That is the CLI's hook runner on another step, recorded as corroboration rather
than as the `stop` measurement.

On Windows none of this can be measured headless: `cursor-agent` is not installable there.
`cursor.com/install` is a bash script whose `uname -s` case accepts `Linux*` and `Darwin*` and exits
1 on anything else, and it mentions
Windows nowhere. The runs recorded below drove the **editor's** agent instead, which is a different
question with a different answer and is labeled as such.

**And the Windows launcher does not route `agent` at all**, which the paragraph above predicts and
which is worth having measured directly rather than inferred twice over. `cursor.cmd` on Windows is
seven lines that hand every argument to the editor CLI, with none of the macOS launcher's branches:

```bat
"%~dp0..\..\..\Cursor.exe" "%~dp0..\out\cli.js" %*
```

So `cursor agent --help` on 3.7.21 prints the **editor's** help, exits 0, and reports no error --
while listing, at the bottom of that same output, `agent  Start the Cursor agent in your terminal.`
The binary advertises a subcommand it does not implement. Reading that help is how this was got
wrong in both directions before it was run.

What *was* run, on 2026-09-22: given a planted sink and Cursor's documented stdin, the script printed

```
{"followup_message":"The fleet has news:\n[directive] PROBE-INDIA cursor shape from otherdev at 2026-09-22T16:40:00+00:00"}
```

and cleared the sink. So the claim is that the script emits the documented shape and selects it from
the payload correctly, not that Cursor accepts it.

`beforeMCPExecution` and `afterMCPExecution` are outbound only -- they gate and audit rather than
feed the agent -- so wiring this to the bridge's own MCP traffic is not available. `stop` is the hook
that matches a turn boundary, but it is **not** Cursor's only injecting hook: `postToolUse` and
`postToolUseFailure` carry `additional_context`, and `sessionStart` carries it too. Read from Cursor's
hooks documentation on 2026-09-22 and none of it run. `postToolUse` is the interesting one and is
deliberately not documented here -- it would deliver fleet events after every tool call rather than
once a turn, which is a different trade in interruption and cost than the one
[#59](https://github.com/robot-council/cli/issues/59) settled.

### Codex

```json
{
  "hooks": {
    "Stop": [
      {
        "hooks": [
          {
            "type": "command",
            "command": "/absolute/path/to/robot-council-stop-hook",
            "timeout": 20
          }
        ]
      }
    ]
  }
}
```

Set `ROBOT_COUNCIL_HARNESS=codex` in the script.

**Not run, and the block above is this project's arrangement rather than a quoted example.** Codex is
not installed on the machine this was written on. What was checked, on 2026-09-22, is that OpenAI's
hooks reference gives `{"decision": "block", "reason": "..."}` as the continuation output and
`stop_hook_active` as the loop guard -- the same two the Claude Code path uses, which is why the
script needs no Codex-specific branch. The nesting, `type`, and `timeout` around them are copied from
the Claude Code shape because the reference presents the same structure; the file it belongs in, and
the equivalent `[[hooks.Stop]]` block for `config.toml`, were not confirmed. Send a correction if it
does not fire.

### Solo

**Not run, and nothing about it was checked.** Solo is not installed on the machine this was written
on, and no Solo documentation was read. If it launches one of the harnesses above, that harness's
section is the whole of it -- but that is the expectation recorded in
[#6](https://github.com/robot-council/cli/issues/6), not a measurement.

## Development

```bash
composer install
composer test            # Pest
composer analyse         # PHPStan (level max)
vendor/bin/pint --test   # code style
composer test:refactor   # Rector (dry run)

./robot-council about
```

A checkout runs as `production`, the same as an install, so Laravel Zero's development commands are hidden. Add `--env=development` to reach them: `./robot-council --env=development make:command FooCommand`.

## License

The MIT License (MIT). See [License File](LICENSE.md).
