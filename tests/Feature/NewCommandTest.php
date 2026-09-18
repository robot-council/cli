<?php

declare(strict_types=1);

/**
 * Scaffolding a fleet service.
 *
 * Every external program is faked. `composer create-project` pulls most of a Laravel install, so a
 * test that really ran it would be a network test wearing a unit test's clothes -- slow, and red
 * whenever Packagist is.
 *
 * **The assertions worth their place are about what reaches `.env`**, because that is the one
 * artifact this command leaves behind that nothing downstream validates: a client secret mangled on
 * the way in fails much later, at a sign-in, with nothing pointing back here.
 *
 * @command  vendor/bin/pest --compact tests/Feature/NewCommandTest.php
 */

use App\Support\GitHub\AccountLookupFailed;
use App\Support\GitHub\Accounts;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

/**
 * A directory this test owns, removed afterwards.
 */
function scaffoldRoot(): string
{
    $root = sys_get_temp_dir().'/rc-new-'.bin2hex(random_bytes(6));

    mkdir($root, 0o700, true);

    return $root;
}

/**
 * Remove a directory and everything under it.
 */
function removeTree(string $directory): void
{
    if (! is_dir($directory)) {
        return;
    }

    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($entries as $entry) {
        /** @var SplFileInfo $entry */
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }

    rmdir($directory);
}

/**
 * The command a `PendingProcess` was going to run, as a plain string.
 *
 * **Not `getCommandline()`, which is what the fake matcher uses.** Symfony escapes an array command
 * per argument -- `'composer' 'create-project' ...` on a Unix shell, and differently on Windows -- so
 * a glob pattern written against the readable form silently matches nothing and the catch-all
 * answers instead. Measured: it made a fake that never created its directory look like a command
 * that could not read `.env`.
 *
 * @param  mixed  $process  The pending process the fake was handed.
 */
function commandOf(mixed $process): string
{
    $command = \is_object($process) && property_exists($process, 'command') ? $process->command : null;

    if (\is_array($command)) {
        return implode(' ', array_map(
            static fn (mixed $part): string => \is_scalar($part) ? (string) $part : '',
            $command
        ));
    }

    return \is_scalar($command) ? (string) $command : '';
}

/**
 * Fake every external program, with `composer create-project` leaving what it would have left.
 *
 * **The directory is created by the fake rather than by the test**, and that is not a detail. The
 * command refuses a name that already exists, so a directory made up front is refused before
 * Composer is ever reached -- which reads as a broken command and is a broken fixture.
 *
 * @param  array<string, string>  $failing  Command prefixes that should fail, and what they say.
 */
function fakeScaffold(string $root, string $name, string $env = "APP_NAME=Laravel\nAPP_URL=http://localhost\n", array $failing = []): void
{
    Process::fake(function (mixed $process) use ($root, $name, $env, $failing) {
        $command = commandOf($process);

        foreach ($failing as $prefix => $said) {
            if (str_starts_with($command, $prefix)) {
                return Process::result(output: '', errorOutput: $said, exitCode: 1);
            }
        }

        if (str_starts_with($command, 'composer create-project')) {
            mkdir($root.'/'.$name, 0o700, true);

            file_put_contents($root.'/'.$name.'/.env', $env);
        }

        return Process::result('');
    });
}

/**
 * Run `new`, narrowed to the pending command the expectation methods live on.
 *
 * `TestCase::artisan()` is declared to return `PendingCommand|int` -- an int when the application
 * has no output to capture -- so every `expectsQuestion()` on it is a call on a possible int as far
 * as the analyzer is concerned. Narrowing once here beats narrowing at a dozen call sites.
 *
 * @param  TestCase  $test  The test case, which is what `$this` is inside a Pest closure.
 * @param  array<string, mixed>  $parameters  The arguments and options.
 */
function runNew(TestCase $test, array $parameters): PendingCommand
{
    $pending = $test->artisan('new', $parameters);

    if (! $pending instanceof PendingCommand) {
        throw new RuntimeException('artisan() returned no pending command to make expectations on.');
    }

    return $pending;
}

beforeEach(function (): void {
    $this->root = scaffoldRoot();
    $this->previous = (string) getcwd();

    chdir($this->root);

    Process::preventStrayProcesses();
    Http::preventStrayRequests();
});

afterEach(function (): void {
    chdir($this->previous);

    removeTree($this->root);
});

it('refuses a name that would scaffold outside the directory', function (string $name): void {
    Process::fake();

    runNew($this, ['name' => $name])
        ->expectsOutputToContain('A name is letters, digits, dots, dashes and underscores')
        ->assertExitCode(1);

    // Nothing ran: the refusal is before any external command, not a cleanup after one
    Process::assertNothingRan();
})->with([
    'a parent traversal' => '../elsewhere',
    'an absolute path' => '/etc',
    'a leading dot' => '.hidden',
    'empty' => '',
]);

it('refuses a directory that already exists, rather than scaffolding into it', function (): void {
    Process::fake();

    mkdir($this->root.'/taken');

    runNew($this, ['name' => 'taken'])
        ->expectsOutputToContain('already exists')
        ->assertExitCode(1);

    Process::assertNothingRan();
});

it('creates, requires, installs and migrates, in that order', function (): void {
    fakeScaffold($this->root, 'fleet');

    runNew($this, ['name' => 'fleet', '--skip-github' => true])
        ->assertExitCode(0);

    // **`assertRanInOrder`, not `assertRan` with a collecting callback.** `assertRan` stops at the
    // first match, so a callback that records and returns true collects exactly one command and
    // reads as a complete list -- it reported 1 of these 5 before this was changed. This assertion
    // also pins the count, which is what says nothing else ran: with `--skip-github` there is no
    // `gh` lookup, so five is the whole of it.
    $expected = [
        'composer create-project laravel/laravel fleet --no-interaction',
        'composer config repositories.robot-council vcs https://github.com/robot-council/core.git',
        'composer require robot-council/core:dev-main --with-all-dependencies --no-interaction',
        'php artisan robot-council:install --no-interaction',
        'php artisan migrate --force --no-interaction',
    ];

    Process::assertRanInOrder(array_map(
        static fn (string $command): Closure => static fn (mixed $process): bool => commandOf($process) === $command,
        $expected
    ));
});

it('stops at the step that failed rather than reporting a service it did not finish', function (): void {
    fakeScaffold($this->root, 'fleet', failing: ['composer require' => 'Could not resolve robot-council/core']);

    runNew($this, ['name' => 'fleet', '--skip-github' => true])
        ->expectsOutputToContain('Could not resolve robot-council/core')
        ->assertExitCode(1);

    // The installer never ran, because requiring the package did not succeed
    Process::assertDidntRun(fn (mixed $process): bool => str_contains(commandOf($process), 'robot-council:install'));
});

it('writes the OAuth credentials and the allowlist it resolved', function (): void {
    Http::fake([
        'api.github.com/users/joshdaugherty' => Http::response(['id' => 4242, 'login' => 'joshdaugherty']),
        'api.github.com/users/someoneelse' => Http::response(['id' => 77, 'login' => 'someoneelse']),
    ]);

    fakeScaffold($this->root, 'fleet');

    runNew($this, ['name' => 'fleet', '--app-url' => 'https://fleet.example.test'])
        ->expectsOutputToContain('https://fleet.example.test/robot-council/auth/github/callback')
        ->expectsQuestion('Client ID', 'Iv1.abc123')
        ->expectsQuestion('Client secret', 'secret-value')
        ->expectsQuestion('Logins', 'joshdaugherty, someoneelse')
        ->assertExitCode(0);

    $env = (string) file_get_contents($this->root.'/fleet/.env');

    expect($env)
        ->toContain('GITHUB_CLIENT_ID=Iv1.abc123')
        ->toContain('GITHUB_CLIENT_SECRET=secret-value')
        ->toContain('GITHUB_REDIRECT_URI=https://fleet.example.test/robot-council/auth/github/callback')
        // Logins in, IDs out. The person never sees a number they had to find.
        ->toContain('ROBOT_COUNCIL_DEVELOPERS=4242,77')
        // The first login named is the admin
        ->toContain('ROBOT_COUNCIL_ADMINS=4242')
        // Rewritten in place rather than appended beside the scaffolded value
        ->toContain('APP_URL=https://fleet.example.test')
        ->not->toContain('APP_URL=http://localhost');
});

it('writes a secret containing regular-expression syntax exactly as given', function (): void {
    Http::fake(['api.github.com/*' => Http::response(['id' => 4242])]);

    fakeScaffold($this->root, 'fleet', "APP_URL=http://localhost\nGITHUB_CLIENT_SECRET=old\n");

    // `$1` and a backslash are back-reference syntax to `preg_replace`'s replacement argument. A
    // GitHub secret is 40 characters this code does not choose, so one arriving with either in it
    // must be written whole rather than substituted into.
    $secret = 'a$1b\\2c$0d';

    runNew($this, ['name' => 'fleet', '--app-url' => 'https://fleet.example.test'])
        ->expectsQuestion('Client ID', 'Iv1.abc123')
        ->expectsQuestion('Client secret', $secret)
        ->expectsQuestion('Logins', 'joshdaugherty')
        ->assertExitCode(0);

    $env = (string) file_get_contents($this->root.'/fleet/.env');

    expect($env)->toContain('GITHUB_CLIENT_SECRET='.$secret)
        ->and(substr_count($env, 'GITHUB_CLIENT_SECRET='))->toBe(1);
});

it('asks again for a login GitHub has no account for', function (): void {
    Http::fake([
        'api.github.com/users/tyop' => Http::response(['message' => 'Not Found'], 404),
        'api.github.com/users/joshdaugherty' => Http::response(['id' => 4242]),
    ]);

    fakeScaffold($this->root, 'fleet');

    runNew($this, ['name' => 'fleet', '--app-url' => 'https://fleet.example.test'])
        ->expectsQuestion('Client ID', 'Iv1.abc123')
        ->expectsQuestion('Client secret', 'secret-value')
        ->expectsQuestion('Logins', 'tyop')
        ->expectsOutputToContain('GitHub has no account called `tyop`')
        ->expectsQuestion('Try another login, or leave blank to skip', 'joshdaugherty')
        ->assertExitCode(0);

    expect((string) file_get_contents($this->root.'/fleet/.env'))
        ->toContain('ROBOT_COUNCIL_DEVELOPERS=4242');
});

it('does not ask again when GitHub could not be asked at all', function (): void {
    Http::fake(['api.github.com/*' => Http::response(['message' => 'rate limited'], 403)]);

    fakeScaffold($this->root, 'fleet');

    // No `Try another login` question is queued. Were the command to ask one, the test would fail
    // on an unexpected question rather than pass quietly -- which is the point: a login that was
    // correct all along must not be re-prompted because the lookup was unavailable.
    runNew($this, ['name' => 'fleet', '--app-url' => 'https://fleet.example.test'])
        ->expectsQuestion('Client ID', 'Iv1.abc123')
        ->expectsQuestion('Client secret', 'secret-value')
        ->expectsQuestion('Logins', 'joshdaugherty')
        ->expectsOutputToContain('rate limiting this address')
        ->expectsOutputToContain('Set ROBOT_COUNCIL_DEVELOPERS in .env')
        ->assertExitCode(0);

    expect((string) file_get_contents($this->root.'/fleet/.env'))
        ->not->toContain('ROBOT_COUNCIL_DEVELOPERS=');
});

it('reports an account lookup that could not be made differently from one that failed', function (): void {
    Http::fake(['api.github.com/*' => Http::response(['id' => 1], 500)]);

    $failure = null;

    try {
        app(Accounts::class)->id('whoever');
    } catch (Throwable $throwable) {
        $failure = $throwable;
    }

    expect($failure)->toBeInstanceOf(AccountLookupFailed::class);

    // Narrowed by a throw rather than by the expectation above: `expect()` tells the analyzer
    // nothing, so reading `->unknown` off `Throwable|null` is an error at max level
    if (! $failure instanceof AccountLookupFailed) {
        throw new RuntimeException('expected an AccountLookupFailed');
    }

    expect($failure->unknown)->toBeFalse();
});

it('treats a network failure like any other lookup it could not make', function (): void {
    // The third row of #13's table. A connection that never opened says nothing about the login, so
    // the command must not re-prompt -- no `Try another login` question is queued here, and Pest
    // fails an unexpected question rather than passing quietly.
    Http::fake(fn (): never => throw new ConnectionException('Connection refused'));

    fakeScaffold($this->root, 'fleet');

    runNew($this, ['name' => 'fleet', '--app-url' => 'https://fleet.example.test'])
        ->expectsQuestion('Client ID', 'Iv1.abc123')
        ->expectsQuestion('Client secret', 'secret-value')
        ->expectsQuestion('Logins', 'joshdaugherty')
        ->expectsOutputToContain('Could not reach GitHub')
        ->assertExitCode(0);

    // The OAuth half still landed: one step failing must not discard the answers already given
    expect((string) file_get_contents($this->root.'/fleet/.env'))
        ->toContain('GITHUB_CLIENT_ID=Iv1.abc123')
        ->not->toContain('ROBOT_COUNCIL_DEVELOPERS=');
});

it('offers the login gh is signed in as, when gh is there', function (): void {
    Http::fake(['api.github.com/*' => Http::response(['id' => 4242])]);

    Process::fake(function (mixed $process) {
        $command = commandOf($process);

        if (str_starts_with($command, 'gh api user')) {
            return Process::result("joshdaugherty\n");
        }

        if (str_starts_with($command, 'composer create-project')) {
            mkdir($this->root.'/fleet', 0o700, true);

            file_put_contents($this->root.'/fleet/.env', "APP_URL=http://localhost\n");
        }

        return Process::result('');
    });

    // The default is what an empty answer takes, which is how the offer is exercised rather than
    // merely printed: nothing is typed for `Logins` here
    runNew($this, ['name' => 'fleet', '--app-url' => 'https://fleet.example.test'])
        ->expectsQuestion('Client ID', 'Iv1.abc123')
        ->expectsQuestion('Client secret', 'secret-value')
        ->expectsQuestion('Logins', 'joshdaugherty')
        ->assertExitCode(0);

    Process::assertRan(fn (mixed $process): bool => str_starts_with(commandOf($process), 'gh api user'));

    expect((string) file_get_contents($this->root.'/fleet/.env'))->toContain('ROBOT_COUNCIL_DEVELOPERS=4242');
});

it('does not fail when gh is absent or signed out', function (): void {
    Http::fake(['api.github.com/*' => Http::response(['id' => 4242])]);

    fakeScaffold($this->root, 'fleet', failing: ['gh api user' => 'gh: command not found']);

    runNew($this, ['name' => 'fleet', '--app-url' => 'https://fleet.example.test'])
        ->expectsQuestion('Client ID', 'Iv1.abc123')
        ->expectsQuestion('Client secret', 'secret-value')
        ->expectsQuestion('Logins', 'joshdaugherty')
        ->assertExitCode(0);

    expect((string) file_get_contents($this->root.'/fleet/.env'))->toContain('ROBOT_COUNCIL_DEVELOPERS=4242');
});
