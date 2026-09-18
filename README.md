# robot-council/cli

[![CI](https://github.com/robot-council/cli/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/robot-council/cli/actions/workflows/ci.yml?query=branch%3Amain)

The command line for [Robot Council](https://github.com/robot-council/core), a coordination service for fleets of AI coding agents. It is a [Laravel Zero](https://laravel-zero.com) application, and it sits beside the package the way `statamic/cli` sits beside `statamic/cms`.

Three commands, designed in [#1](https://github.com/robot-council/cli/issues/1):

- **`robot-council enroll`** — enroll this machine. It requests a device code, prints the user code and the verification URL, and polls until a developer approves. The credential it receives is stored in the OS keychain or a user-only file, and never printed.
- **`robot-council mcp`** — the stdio MCP bridge. An agent harness launches it, and it serves the coordination tools over stdio while renewing its own session token, so a token expiring needs no restart and no human.
- **`robot-council api`** — the fallback for anything the bridge does not cover.

## Requirements

- PHP 8.4 or later

## Getting a machine onto a fleet

```bash
robot-council enroll --service=https://your-fleet.example.com
```

It prints a code and a URL. A developer signed in to that fleet opens the URL, enters the code, and approves; enrollment finishes on its own. Then wire a harness to the bridge, below.

## Wiring the bridge into a harness

**A harness never receives the credential.** Its configuration carries the service URL and nothing else — the bridge reads the credential itself, from the keychain, in its own process. That is the reason the bridge exists rather than the harness speaking to the service directly: a token in harness configuration is a token in every transcript that configuration is dumped into, and agents dump their configuration.

So the only secret-adjacent thing below is a URL, and each harness is configured the same way: launch `robot-council mcp` as a stdio server, with `ROBOT_COUNCIL_SERVICE` in its environment.

Pass `--project=<repository or workspace>` as well where one harness works several checkouts, so the fleet can tell the sessions apart.

### Claude Code

```bash
claude mcp add robot-council \
  -e ROBOT_COUNCIL_SERVICE=https://your-fleet.example.com \
  -- robot-council mcp
```

**Verified 2026-09-18**, on Claude Code 2.1.236 and macOS 26.6.2, against a live fleet: `claude mcp list` reported `robot-council` as `Connected`, and a raw `initialize` plus `tools/list` over the same command served 15 tools with nothing but protocol on stdout.

Two controls ran beside it, because a health check that answers `Connected` for anything answers nothing: the same command at a path that does not exist failed with `ENOENT`, and **the real binary with `ROBOT_COUNCIL_SERVICE` omitted failed with `CONNECTION_CLOSED`**. The second is the one that matters — it is the same binary, missing only the variable, so the passing reading is evidence the variable reached the process and a session started on the service.

If `robot-council` is not on your `PATH`, give the absolute path to the executable instead — and **quote it if it contains a space**, because the failure is silent. Measured on 2026-09-18 with the path `/Users/…/GitHub Repos/robot-council-cli/robot-council`: unquoted, `claude mcp add` exits 0 and prints `Added stdio MCP server … with command: <the whole path> mcp`, which reads as correct, while what it wrote was `"command": "/Users/…/GitHub"` with `"args": ["Repos/robot-council-cli/robot-council", "mcp"]`. The success message rejoins the split with spaces, so the only place the damage is visible is `~/.claude.json`.

### Codex

```toml
[mcp_servers.robot-council]
command = "robot-council"
args = ["mcp"]
env = { ROBOT_COUNCIL_SERVICE = "https://your-fleet.example.com" }
```

**Not run.** Codex is not installed on the machine this was written on, so nothing above launched a bridge. What *was* checked, on 2026-09-18, is the spelling: `command`, `args`, and `env` are the key names OpenAI's [configuration reference](https://learn.chatgpt.com/docs/config-file/config-reference) gives under `mcp_servers.<id>`. That reference carries no combined example, so the arrangement of those keys into the block above is this project's, and the claim here is about three key names and the table they sit under, not about a working setup. Send a correction if it does not launch.

### Solo

**Not run, and nothing about it was checked.** Solo is not installed on the machine this was written on. The expectation recorded in [#6](https://github.com/robot-council/cli/issues/6) is that it is configured through whichever harness it launches, which would make the Claude Code section above the whole of it — but that is an expectation, not a measurement, and no Solo documentation was read to support it.

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
