# robot-council/cli

[![CI](https://github.com/robot-council/cli/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/robot-council/cli/actions/workflows/ci.yml?query=branch%3Amain)

The command line for [Robot Council](https://github.com/robot-council/core), a coordination service for fleets of AI coding agents. It is a [Laravel Zero](https://laravel-zero.com) application, and it sits beside the package the way `statamic/cli` sits beside `statamic/cms`.

Four commands, designed in [#1](https://github.com/robot-council/cli/issues/1) and [#13](https://github.com/robot-council/cli/issues/13):

- **`robot-council enroll`** — enroll this machine. It requests a device code, prints the user code and the verification URL, and polls until a developer approves. The credential it receives is stored in the OS keychain or a user-only file, and never printed.
- **`robot-council mcp`** — the stdio MCP bridge. An agent harness launches it, and it serves the coordination tools over stdio while renewing its own session token, so a token expiring needs no restart and no human.
- **`robot-council api`** — the fallback for anything the bridge does not cover.

- **`robot-council new`** — create a fleet service in this directory, the way `statamic new` creates a site.

## Requirements

- PHP 8.4 or later

## Installing

Not published on Packagist yet, so point Composer at this repository and install it globally:

```bash
composer global config repositories.robot-council vcs https://github.com/robot-council/cli.git
composer global require robot-council/cli:dev-main
```

Put Composer's global `vendor/bin` on your `PATH` — `composer global config bin-dir --absolute` prints it — and `robot-council` is available everywhere, which is what the harness setups below assume.

**Verified 2026-09-18** on macOS 26.6.2 with PHP 8.4, into a throwaway `COMPOSER_HOME`: the install exits 0, `vendor/bin/robot-council` is written, and `robot-council list` shows `about`, `api`, `enroll`, and `mcp`.

## Creating a fleet service

```bash
robot-council new my-fleet
```

It creates a Laravel application in the current directory, requires `robot-council/core`, runs the package's installer, and migrates. Then it prints the exact OAuth callback URL to paste into GitHub, takes the client ID and secret back, and asks who may sign in.

**The allowlist is asked for as logins and stored as numeric IDs.** The service checks numeric GitHub account IDs, because a login can be renamed and then claimed by somebody else -- but nobody should have to go and find a number, so each login is resolved once, here, and the mapping is printed before it is written.

`--app-url` overrides where the application will be served, which decides the callback URL; it defaults to Herd's `http://<name>.test`. `--skip-github` scaffolds without asking anything.

## Getting a machine onto a fleet

```bash
robot-council enroll --service=https://your-fleet.example.com
```

It prints a code and a URL. A developer signed in to that fleet opens the URL, enters the code, and approves; enrollment finishes on its own. Then wire a harness to the bridge, below.

**Enroll once per harness, not once per machine.** A credential belongs to one harness, so a machine running both Claude Code and Cursor enrolls twice:

```bash
robot-council enroll --service=https://your-fleet.example.com --harness=claude
robot-council enroll --service=https://your-fleet.example.com --harness=cursor
```

Re-enrolling a harness **replaces** that harness's credential and leaves the others alone. Worktrees do not need one each: several checkouts under one harness share its credential and are told apart by `--project`.

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

### Claude Code

```bash
claude mcp add robot-council \
  -e ROBOT_COUNCIL_SERVICE=https://your-fleet.example.com \
  -e ROBOT_COUNCIL_HARNESS=claude \
  -- robot-council mcp
```

**Verified 2026-09-21**, on Claude Code 2.1.236 and macOS 26.6.2, against a live fleet, with three configurations differing only in that variable:

| `ROBOT_COUNCIL_HARNESS` | `claude mcp list` |
| --- | --- |
| `claude` | `Connected` |
| *omitted* | `Connected` — Claude Code exports a variable the detector reads, so detection alone is enough here |
| `cursor`, which this machine has not enrolled | `Failed to connect` |

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

After enroll with `--harness=cursor`, Settings → Tools & MCP showed the server connected. Reloading it there closed the old stdio process and started a new one: the fleet's agents list marked the previous session `gone` and showed a new `active` session for the same machine, harness `cursor`, last seen within seconds. That is the control that matters — the dashboard reading changes with the reload, so the variable reached the process and a session started on the service.

Put the entry in the **global** file when the fleet URL is yours. A project `.cursor/mcp.json` is shared with whoever opens that checkout; this repository does not ship one, because the URL is per deployment rather than part of the CLI source.

If `robot-council` is not on the `PATH` Cursor inherits, point `command` at `php` (absolute path to the binary) and put the absolute path to this repository's `robot-council` script, then `mcp`, in `args`. Measured on the same Windows run: without that, Windows offered "Select an app to open `robot-council`" instead of launching the bridge.

### Codex

```toml
[mcp_servers.robot-council]
command = "robot-council"
args = ["mcp"]
env = { ROBOT_COUNCIL_SERVICE = "https://your-fleet.example.com", ROBOT_COUNCIL_HARNESS = "codex" }
```

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

#### Why it is written out rather than derived

A folder name is the obvious source and the wrong one, for three reasons, all of them measurable:

- **It loses the organization.** `uams-statamic` alone is not a repository; two organizations can both have one.
- **It is whatever that machine called the directory.** A worktree at `uams-statamic-a` on one machine and `statamic-a` on another reports two projects for one thing, which defeats the comparison the label exists for.
- **A path does not fit.** `ProjectId` accepts `[A-Za-z0-9._/-]` — forward slash is in it, **backslash is not** — so `C:\Users\Josh\Herd\uams-statamic-a` is refused by the service outright. A configuration that interpolates `${workspaceFolder}` fails at the far end, mid-wire-up, rather than where it was written.

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

The bridge presents one harness's credential, so it will not run without knowing which harness it is. Three refusals, each naming what to do:

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

All three were produced by running the bridge on 2026-09-21 rather than read off the source, which is why the second line of the first one says `cursor, claude`. All three go to stderr, prefixed `robot-council:`. `mcp`'s stdout is a protocol stream a harness parses, so nothing else ever goes there.

**The list of enrolled harnesses is a lower bound.** It is built by asking about each harness `laravel/agent-detector` knows, because no credential store can list its keys, so a harness named by hand that the detector has never heard of will not appear in it.

### Any other harness

Anything that speaks MCP over stdio works: the server command is `robot-council mcp` and the one environment variable is `ROBOT_COUNCIL_SERVICE`. The bridge writes protocol to stdout and diagnostics to stderr, and never mixes them.

## Development

```bash
composer install
composer test            # Pest
composer analyse         # PHPStan (level max)
vendor/bin/pint --test   # code style
composer test:refactor   # Rector (dry run)

./robot-council about
```

## License

The MIT License (MIT). See [License File](LICENSE.md).
