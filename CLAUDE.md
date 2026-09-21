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
| Mutation | `composer test:mutate -- --class='App\Support\Credentials\<ChangedClass>'` (needs PCOV or Xdebug; see the `pcov-setup` skill) |

## Reading a mutation run

`composer test:mutate` scopes to `app/`, and a run is **diff-scoped by naming the class you changed** rather than sweeping the whole application. Without `--class` it mutates everything, which is slow and produces a list nobody reads.

**The plugin is not in `require-dev`, and should not be.** `pestphp/pest` v5.2.1 requires `pestphp/pest-plugin-mutate ^5.0.2` outright, so it arrives with Pest rather than as an optional extra. Declaring it again would duplicate a constraint Pest owns and could pin a version Pest does not want.

**The criterion is no unexplained survivors, not a score.** Every survivor is killed, deleted as dead code, or annotated with the reason it cannot be killed, where the survivor is. A percentage merges an unexamined survivor with a provably equivalent one, and a threshold turns "explain this survivor" into "delete a defensive guard to move the number."

Four ways the tool reports success it has not earned:

- **`0 Mutations for 0 Files created` is a setup failure, not a pass**, and it exits `0`. Measured 2026-09-21 with a `--class` naming a class that does not exist: `0 Mutations for 0 Files created`, `Score: 0.00%`, **exit 0**. A typo in the class name therefore passes anything reading only the exit code, and the score it prints is the one a reader is least likely to mistake for success -- which is luck, not design. Read the population first.
- **A score computed where the covering tests SKIP means nothing.** Measured 2026-09-21: `--class='App\Support\Credentials\WindowsCredentialStore'` on macOS reports **70 of 85 mutants uncovered and a score of 3.53%**, because almost every test in `WindowsCredentialStoreTest` is gated on `available()` and there is no Credential Manager to be had. The tests are fine; the platform is wrong. Read the `uncovered` count, and run a platform-gated class where its tests run.
- **v5.0.2 records any child process that exits unsuccessfully as killed**, whether or not the covering test ran, so a **false kill leaves nothing to explain**. The control is to skip the single covering test, confirm it is genuinely skipped, and require the mutant to flip to survivor.
- **`// @pest-mutate-ignore` is whitespace-sensitive to the point of being inert.** The marker carries no prose on its own line -- v5.0.2 captures the rest of that line and compares it against mutator names, so `// @pest-mutate-ignore: X because Y` suppresses **nothing**, while `// @pest-mutate-ignore: X, because Y` works. A bare marker with one trailing space is also inert, and Pint strips trailing whitespace, so a formatting-only commit can turn an inert marker into a suppress-everything one. Put the reason on preceding lines and verify the annotation did something: the population drops by one, rather than the survivor merely disappearing from view. `Credentials::storedAmong()` is the worked example.

**A survivor that another gate already catches is annotated rather than tested around.** `UnwrapArrayValues` on `Credentials::storedAmong()` survives the suite and fails `composer analyse`, because `array_filter` preserves keys and the signature promises `list<string>`. Writing a test for it would duplicate a stronger check.

## Things that are easy to get wrong

- **`composer.lock` IS committed here, unlike in `robot-council/core`.** This is an application: it is installed from its own lock rather than resolved into somebody else's dependency graph, so CI installs from the lock and there is no `prefer-lowest` cell. Dependabot moves the lock.
- **Application classes are `final`, with no `protected` methods**, and `sleep()`, `assert()`, and the weak random functions are unavailable. Pest's `strict()` and `security()` presets enforce it over `App\`, the same as in the package.
- **CI is one workflow with one required check.** `tests` runs on ubuntu and windows × PHP 8.5 and 8.4; `phpstan`, `pint`, and `rector` fail rather than fixing. `ci-passed` succeeds only when every other job did, and it is the one check the `main` ruleset requires.
- **A credential must never reach stdout or a log.** Enrollment exists in this repository rather than in an agent's own shell precisely so that the token does not become tool output. The user code and the verification URL are the only things enrollment prints.
- **`main` is guarded by a ruleset**: a pull request, a successful `ci-passed`, and a branch up to date with `main`.
- **Never disclose an exploitable vulnerability in a public issue or PR.** Use a draft security advisory.

## Where the conventions live

`.claude/rules/` loads into every session, and `.claude/skills/` loads on demand. They are the same files `robot-council/core` carries, and a change worth making applies to both repositories.
