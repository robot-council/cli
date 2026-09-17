---
name: pest-testing
description: "Use this skill for Pest PHP testing in this Laravel package (Pest 5 on Orchestra Testbench). Trigger whenever any test is being written, edited, fixed, or refactored — including fixing tests that broke after a code change, adding assertions, converting PHPUnit to Pest, adding datasets, and TDD workflows. Always activate when the user asks how to write something in Pest, mentions test files or the tests/ directory, tests/Pest.php, tests/TestCase.php, or tests/ArchTest.php, or needs architecture tests. Covers: test()/it()/expect() syntax, running tests with composer test or vendor/bin/pest, datasets, mocking and facade-mock cleanup, console command assertions, arch(), and the Testbench TestCase. Do not use for factories, migrations, service providers, or non-test PHP code."
license: MIT
metadata:
  author: laravel
---

# Pest Testing

## Documentation

Read the installed version rather than recalling another one: `composer show pestphp/pest`
(5.2.1 when this was written; `composer.lock` is not committed, so a fresh install can resolve
differently) and its source under `vendor/pestphp/pest/src` and
`vendor/pestphp/pest-plugin-arch/src`. For prose documentation use
[pestphp.com/docs](https://pestphp.com/docs) and, for the package test harness,
[Orchestra Testbench](https://packages.tools/testbench).

## Basic Usage

### How the suite is wired

- Tests live directly in `tests/` as `*Test.php` files (`phpunit.xml.dist` defines one suite over
  `tests`). There is no `Feature/` / `Unit/` split yet.
- `tests/Pest.php` does `pest()->extend(TestCase::class)->in(__DIR__)`, so every test file runs on
  `RobotCouncil\Tests\TestCase`, which extends `Orchestra\Testbench\TestCase` and
  registers `RobotCouncilServiceProvider`. The package is booted inside a Testbench application
  in every test; `$this->app`, facades, and `$this->artisan()` are available.
- `tests/ArchTest.php` holds the architecture tests: Pest's `php()`, `security()`, and `strict()`
  presets, applied to the package's namespaces but not to `tests/`.
- A test that reads data a second connection commits goes in the `cross-connection` group
  (`->group('cross-connection')`). `phpunit.xml.dist` excludes that group from every run that
  does not name it, and only CI's `postgres` job runs it (`--group=cross-connection`), because
  each connection to SQLite's in-memory database is a separate database. Such a test must not
  use `RefreshDatabase` or `DatabaseTransactions`, whose wrapping transaction hides its rows from
  the other connection, and it removes what it created in a `finally` block.
  `tests/CrossConnectionTest.php` is the pattern.
- Do NOT remove tests without approval.

### Creating Tests

Create test files by hand in `tests/`. There is no `php artisan` here. `vendor/bin/testbench list`
does show `make:test` (from Orchestra Canvas) and `pest:test`, but both write relative to the
Testbench application's base path — the skeleton under `vendor/orchestra/testbench-core/laravel`
— not this package's `tests/`, and Canvas without a `canvas.yaml` generates feature tests that
extend `Tests\TestCase`, which does not exist here. Do not use them.

### Basic Test Structure

Pest supports both `test()` and `it()`. Check existing files first and match them; the current
tests use `it()`.

```php
it('can test', function () {
    expect(true)->toBeTrue();
});
```

### Running Tests

- Run minimal tests with a filter before finalizing: `vendor/bin/pest --compact --filter='can test'`.
- Run one file: `vendor/bin/pest --compact tests/ExampleTest.php`.
- Run everything: `composer test` (which runs `vendor/bin/pest`) or `vendor/bin/pest --compact`.
- Coverage: `composer test-coverage` (needs a coverage driver; see the
  [`pcov-setup`](../pcov-setup/SKILL.md) skill).
- CI's `tests` job in `.github/workflows/ci.yml` runs `vendor/bin/pest --ci` across its OS / PHP / Laravel matrix.
- Tests are code under the same gates as `src/`: PHPStan analyzes `tests/` at level `max` (with
  `pestphp/pest-plugin-phpstan`, which types `expect()` chains and flags redundant expectations such
  as `expect(true)->toBeTrue()`), and Rector processes it. Run `composer analyse` and
  `composer test:refactor` after writing or changing a test.

`phpunit.xml.dist` sets `executionOrder="random"`, `failOnRisky`, `failOnWarning`, and
`beStrictAboutOutputDuringTests`, so an order-dependent test flakes and a test that prints output
fails.

## Assertions

Use specific assertions instead of generic status or exit-code checks:

```php
it('runs the command', function () {
    $this->artisan('robot-council')
        ->expectsOutput('All done')
        ->assertSuccessful();
});
```

| Use | Instead of |
|-----|------------|
| `assertSuccessful()` | `assertStatus(200)` / `assertExitCode(0)` |
| `assertNotFound()` | `assertStatus(404)` |
| `assertForbidden()` | `assertStatus(403)` |

The HTTP forms apply once the package registers routes; there are none yet.

## Mocking

Mock through the test case: `$this->mock(Service::class, function (MockInterface $mock): void { ... })`
(Laravel's `InteractsWithContainer`, which Testbench's `TestCase` uses), or `Facade::shouldReceive()`
for a facade. `pestphp/pest-plugin-laravel` is not installed, so there is no `Pest\Laravel\mock()`
function to import.

### A mocked facade is still installed during `afterEach` — never clean up through one

In Pest 5.2.1, `Concerns/Testable.php`'s `tearDown()` calls the file's `afterEach` closure inside
a `try` and `parent::tearDown()` — Testbench's teardown, which flushes the application — in the
`finally`. So a facade you swapped inside the test is still the facade root while your
cleanup runs. Cleanup that calls back through it can silently do nothing, and the test still
reports green:

```php
// The test mocks isDirectory() to simulate a stale check...
File::partialMock()->shouldReceive('isDirectory')->with($dir)->andReturnFalse();

// ...and Filesystem::deleteDirectory() OPENS with its own isDirectory() guard,
// so this returns false and the directory survives.
afterEach(fn () => File::deleteDirectory($dir));
```

Clean up through a real instance instead, which no mock can intercept:

```php
afterEach(function () use (&$dirs): void {
    $files = new Illuminate\Filesystem\Filesystem;   // NOT the File facade

    foreach ($dirs as $dir) {
        $files->deleteDirectory($dir);
    }
});
```

It stays silent for a second reason: `AfterEachRepository::get()` chains `Mockery::close()`
**ahead of** the file's `afterEach`, and Mockery checks call counts at close, so an exhausted
`->once()` expectation does **not** fail the test when the cleanup re-enters it. **Whenever a
test both mocks a facade and creates files, verify the cleanup by listing the directory before
and after a run** — green is not evidence here.

## Datasets

Use datasets for repetitive tests (validation rules, input variants):

```php
it('has emails', function (string $email) {
    expect($email)->not->toBeEmpty();
})->with([
    'james' => 'james@laravel.com',
    'taylor' => 'taylor@laravel.com',
]);
```

## Architecture Testing

Architecture tests enforce code conventions. Add them to `tests/ArchTest.php`:

```php
arch('commands extend the framework command')
    ->expect('RobotCouncil\Commands')
    ->toExtend('Illuminate\Console\Command');
```

`php()`, `security()`, and `strict()` are already applied; what they forbid is in
`php-coding-standards` and `vendor/pestphp/pest/src/ArchPresets/`. The `laravel()` preset is not
used: its expectations target the `App\` namespace, which this package does not have.

Rector runs `pestphp/pest-plugin-rector`'s coding-style set over `tests/`, so test code is held to
idiomatic Pest: it rewrites `expect($a === $b)->toBeTrue()` to `expect($a)->toBe($b)`, removes a
leftover `->only()` or debug expectation, and prefers `pest()->extend()` over `uses()`. Run
`composer refactor` after writing tests.

Browser, smoke, and visual-regression testing need Pest plugins that are not installed here.

## Common Pitfalls

- Calling `Pest\Laravel\mock()` or another `Pest\Laravel` function: the plugin is not installed; use `$this->mock()`
- Using `assertStatus(200)` or `assertExitCode(0)` instead of `assertSuccessful()`
- Forgetting datasets for repetitive validation tests
- Deleting tests without approval
- Cleaning up through a facade the test mocked (see Mocking)
- Generating a test with `vendor/bin/testbench make:test` or `pest:test`, which lands under `vendor/`
- Relying on test order or printing output, both of which `phpunit.xml.dist` turns into failures
