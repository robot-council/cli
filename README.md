# robot-council/cli

[![CI](https://github.com/robot-council/cli/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/robot-council/cli/actions/workflows/ci.yml?query=branch%3Amain)

The command line for [Robot Council](https://github.com/robot-council/core), a coordination service for fleets of AI coding agents. It is a [Laravel Zero](https://laravel-zero.com) application, and it sits beside the package the way `statamic/cli` sits beside `statamic/cms`.

Four commands, designed in [#1](https://github.com/robot-council/cli/issues/1) and [#13](https://github.com/robot-council/cli/issues/13):

- **`robot-council enroll`** — enroll this machine. It requests a device code, prints the user code and the verification URL, and polls until a developer approves. The credential it receives is stored in the OS keychain or a user-only file, and never printed.
- **`robot-council mcp`** — the stdio MCP bridge. An agent harness launches it, and it comes up **without joining the fleet**: its one tool is `join`, and the fleet's coordination tools appear once an agent calls it. Once joined, it renews its own session token, so a token expiring needs no restart and no human. Under Claude Code, `--keep-warm=<minutes>` has it wake an idle session to keep its prompt cache warm.
- **`robot-council pending`** — print the fleet events waiting for this harness, and clear them. The bridge reads the change feed while it runs and leaves anything that concerns its own session here; a harness's stop hook runs this at a turn boundary so an agent finds out without being asked. Prints nothing and exits `0` when the fleet has been quiet. It also records when the turn ended, for a bridge keeping the session's cache warm.
- **`robot-council api`** — the fallback for anything the bridge does not cover.

- **`robot-council new`** — create a fleet service in this directory, the way `statamic new` creates a site.

**The documentation lives on [the wiki](https://github.com/robot-council/cli/wiki)**, which is its source of truth ([#318](https://github.com/robot-council/cli/issues/318)). This README is an overview; nothing here is the only statement of anything.

## Install

PHP 8.4 or later. Install into a directory of its own, as versions side by side behind a launcher, so an upgrade never replaces a file a running bridge holds:

```bash
composer create-project robot-council/cli "$HOME/.robot-council-setup" --no-dev
php "$HOME/.robot-council-setup/robot-council" upgrade --root="$HOME/.local/robot-council"
rm -rf "$HOME/.robot-council-setup"
```

Then put `~/.local/robot-council/bin` on your `PATH`. The Windows commands, upgrading, and the `PATH` order that matters are on [Installing and upgrading](https://github.com/robot-council/cli/wiki/Installing-and-upgrading).

## Where to read next

| To | Read |
| --- | --- |
| install, upgrade, or recover an install | [Installing and upgrading](https://github.com/robot-council/cli/wiki/Installing-and-upgrading) |
| create a fleet service, enroll a machine, or set the service's secrets | [Creating a fleet and enrolling a machine](https://github.com/robot-council/cli/wiki/Creating-a-fleet-and-enrolling-a-machine) |
| configure Claude Code, Cursor, Codex, or Solo to launch the bridge | [Wiring the bridge into a harness](https://github.com/robot-council/cli/wiki/Wiring-the-bridge-into-a-harness) |
| join the fleet, ask for a role, declare a capacity, or name a project | [Joining the fleet and naming a project](https://github.com/robot-council/cli/wiki/Joining-the-fleet-and-naming-a-project) |
| hand fleet events to an agent at a turn boundary | [The stop hook](https://github.com/robot-council/cli/wiki/The-stop-hook), then [under Claude Code](https://github.com/robot-council/cli/wiki/The-stop-hook-under-Claude-Code) or [under Cursor](https://github.com/robot-council/cli/wiki/The-stop-hook-under-Cursor) |
| keep an idle Claude Code session's prompt cache warm | [Keeping an idle session warm](https://github.com/robot-council/cli/wiki/Keeping-an-idle-session-warm) |
| start, restart, or check a running seat | [the operator's guide on the wiki's Home](https://github.com/robot-council/cli/wiki) |

The stop-hook script itself is [`resources/stop-hook/robot-council-stop-hook`](resources/stop-hook/robot-council-stop-hook), which `tests/Feature/StopHookScriptTest.php` runs.

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
