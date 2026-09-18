# robot-council/cli

[![CI](https://github.com/robot-council/cli/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/robot-council/cli/actions/workflows/ci.yml?query=branch%3Amain)

The command line for [Robot Council](https://github.com/robot-council/core), a coordination service for fleets of AI coding agents. It is a [Laravel Zero](https://laravel-zero.com) application, and it sits beside the package the way `statamic/cli` sits beside `statamic/cms`.

**Nothing is built yet.** This repository is the skeleton: the conventions, the checks, and one command that proves the application boots. What it will do is designed in [#1](https://github.com/robot-council/cli/issues/1), transferred here from `robot-council/core` once its blockers closed:

- **Enroll this machine.** It requests a device code, prints the user code and the verification URL, and polls until a developer approves. The credential it receives is stored in the OS keychain or a user-only file, and never printed — which is the point of running enrollment here rather than in an agent's own shell, where the token would become tool output.
- **Run the stdio MCP bridge.** An agent harness launches it, and it serves the coordination tools over stdio while renewing its own session token, so a token expiring needs no restart and no human.
- **Call the API**, as the fallback for anything the bridge does not cover.

## Requirements

- PHP 8.4 or later

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
