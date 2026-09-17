# robot-council/cli

A **[Laravel Zero](https://laravel-zero.com) application**, not a package. It is the command line for Robot Council, designed in [robot-council/core#33](https://github.com/robot-council/core/issues/33), and it sits beside [`robot-council/core`](https://github.com/robot-council/core) the way `statamic/cli` sits beside `statamic/cms`.

**Only the skeleton exists.** One `about` command, the checks, and the conventions. Read #33 before building anything here, and #14 for the service it talks to.

## Layout

- `app/` — namespace `App\`. `Commands/` holds the commands; `Providers/AppServiceProvider.php` is where bindings go.
- `bootstrap/app.php` — the Laravel Zero application, configured the Laravel 11+ way.
- `config/app.php` — the application's name and version. `config/commands.php` decides which commands ship.
- `robot-council` — the executable. Laravel Zero's skeleton calls it `application`; it is renamed here because that is the name it is installed under.
- `tests/` — Pest. `tests/ArchTest.php` applies the `php()`, `security()`, and `strict()` presets to `App\`, exactly as `robot-council/core` does.

## Commands

| Task | Command |
| --- | --- |
| Install | `composer install` |
| Run it | `./robot-council <command>` |
| Tests | `composer test`; one file: `vendor/bin/pest --compact tests/Feature/AboutCommandTest.php` |
| Static analysis | `composer analyse` (PHPStan with Larastan, level `max`, bleeding edge, no baseline) |
| Format | `vendor/bin/pint --dirty`; check only: `vendor/bin/pint --test` |
| Refactor | `composer refactor`; check only: `composer test:refactor` |

## Things that are easy to get wrong

- **`composer.lock` IS committed here, unlike in `robot-council/core`.** This is an application: it is installed from its own lock rather than resolved into somebody else's dependency graph, so CI installs from the lock and there is no `prefer-lowest` cell. Dependabot moves the lock.
- **Application classes are `final`, with no `protected` methods**, and `sleep()`, `assert()`, and the weak random functions are unavailable. Pest's `strict()` and `security()` presets enforce it over `App\`, the same as in the package.
- **CI is one workflow with one required check.** `tests` runs on ubuntu and windows × PHP 8.5 and 8.4; `phpstan`, `pint`, and `rector` fail rather than fixing. `ci-passed` succeeds only when every other job did, and it is the one check the `main` ruleset requires.
- **A credential must never reach stdout or a log.** Enrollment exists in this repository rather than in an agent's own shell precisely so that the token does not become tool output. The user code and the verification URL are the only things enrollment prints.
- **`main` is guarded by a ruleset**: a pull request, a successful `ci-passed`, and a branch up to date with `main`.
- **Never disclose an exploitable vulnerability in a public issue or PR.** Use a draft security advisory.

## Where the conventions live

`.claude/rules/` loads into every session, and `.claude/skills/` loads on demand. They are the same files `robot-council/core` carries, and a change worth making applies to both repositories.
