<?php

declare(strict_types=1);

namespace App\Commands;

use App\Support\EnvFile;
use App\Support\GitHub\AccountLookupFailed;
use App\Support\GitHub\Accounts;
use App\Support\Scaffold;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Support\Facades\Process;
use LaravelZero\Framework\Commands\Command;
use Throwable;

/**
 * Create a fleet service in this directory.
 *
 * Modeled on `statamic new`, and a wrapper around `composer create-project` in the same way. The
 * package is installed into a host Laravel application rather than being one, so what this leaves
 * behind is an ordinary Laravel application that happens to have `robot-council/core` required,
 * installed, and migrated.
 *
 * **Three of the four steps have no judgment in them, and the fourth cannot be automated.** Creating
 * the application, requiring the package, and running its installer are mechanical. Creating the
 * GitHub OAuth application is not: it needs a token this command will not ask for, so the most it
 * can do is print the exact callback URL to paste and take the two values back.
 *
 * **The allowlist is prompted by login and stored by ID.** The config holds numeric account IDs
 * because a login can be renamed and then claimed by somebody else; a person setting up a fleet
 * should still never have to find one. See `Support\GitHub\Accounts`.
 */
#[Description('Create a fleet service in this directory')]
#[Signature('new
    {name : The directory to create it in}
    {--app-url= : Where the application will be served, defaulting to http://<name>.test}
    {--skip-github : Do not ask for GitHub OAuth credentials or an allowlist}')]
final class NewCommand extends Command
{
    /**
     * What a directory name may contain.
     *
     * The value becomes a directory, a Composer package path, and part of a URL, so it is limited
     * rather than merely non-empty. Leading dots and separators are what this is really refusing:
     * `../elsewhere` is a directory name that scaffolds outside the directory the person is in.
     */
    public const string NAME = '/^[A-Za-z0-9][A-Za-z0-9._-]*$/D';

    /**
     * How many times one allowlist entry may be retyped before the command moves on.
     *
     * A person correcting a typo gets another go; a person who cannot produce a login GitHub knows
     * is not helped by being asked forever, and a loop with no bound is a command that cannot be
     * finished without killing it.
     */
    public const int MAX_ATTEMPTS = 3;

    /**
     * Create the application.
     *
     * @param  Scaffold  $scaffold  The external commands to run.
     * @param  Accounts  $accounts  How a GitHub login becomes an account ID.
     * @return int The exit code.
     */
    public function handle(Scaffold $scaffold, Accounts $accounts): int
    {
        $name = $this->text($this->argument('name'));

        if (preg_match(self::NAME, $name) !== 1) {
            $this->components->error('A name is letters, digits, dots, dashes and underscores, and starts with a letter or a digit.');

            return self::FAILURE;
        }

        $working = (string) getcwd();
        $application = $working.\DIRECTORY_SEPARATOR.$name;

        // Refused rather than merged into. `composer create-project` into an occupied directory
        // fails anyway, and failing here says why in one line instead of thirty
        if (file_exists($application)) {
            $this->components->error(sprintf('`%s` already exists. Pick another name, or remove it.', $name));

            return self::FAILURE;
        }

        try {
            $this->components->task('Creating the application', function () use ($scaffold, $name, $working): void {
                $scaffold->createProject($name, $working);
            });

            $this->components->task('Requiring robot-council/core', function () use ($scaffold, $application): void {
                $scaffold->requireCore($application);
            });

            $this->components->task('Installing and migrating', function () use ($scaffold, $application): void {
                $scaffold->install($application);
            });
        } catch (Throwable $throwable) {
            $this->newLine();
            $this->components->error($throwable->getMessage());

            return self::FAILURE;
        }

        $url = $this->applicationUrl($name);

        $values = ['APP_URL' => $url];

        if (! $this->option('skip-github')) {
            $values = [...$values, ...$this->github($url), ...$this->allowlist($accounts)];
        }

        try {
            new EnvFile($application.\DIRECTORY_SEPARATOR.'.env')->set($values);
        } catch (Throwable $throwable) {
            $this->components->error($throwable->getMessage());

            return self::FAILURE;
        }

        $this->done($name, $url);

        return self::SUCCESS;
    }

    /**
     * Ask for the OAuth credentials, having said exactly what to paste into GitHub.
     *
     * @param  string  $url  Where the application will be served.
     * @return array<string, string> What to write to `.env`.
     */
    private function github(string $url): array
    {
        $callback = rtrim($url, '/').'/robot-council/auth/github/callback';

        $this->newLine();
        $this->components->info('Create a GitHub OAuth app at https://github.com/settings/developers, with this callback URL:');
        $this->line('  '.$callback);
        $this->newLine();

        $id = trim($this->text($this->ask('Client ID')));

        // `secret()` rather than `ask()`: the value is a credential, and a terminal keeps scrollback
        $secret = trim($this->text($this->secret('Client secret')));

        if ($id === '' || $secret === '') {
            $this->components->warn('Left blank. Set GITHUB_CLIENT_ID and GITHUB_CLIENT_SECRET in .env before signing in.');

            return [];
        }

        return [
            'GITHUB_CLIENT_ID' => $id,
            'GITHUB_CLIENT_SECRET' => $secret,
            'GITHUB_REDIRECT_URI' => $callback,
        ];
    }

    /**
     * Ask who may sign in, by login, and write numeric IDs.
     *
     * @param  Accounts  $accounts  How a login becomes an account ID.
     * @return array<string, string> What to write to `.env`.
     */
    private function allowlist(Accounts $accounts): array
    {
        $this->newLine();
        $this->components->info('Who may sign in? GitHub logins, comma separated. The first is an admin.');

        $logins = array_values(array_filter(array_map(
            trim(...),
            explode(',', $this->text($this->ask('Logins', $this->suggestedLogin())))
        ), static fn (string $login): bool => $login !== ''));

        if ($logins === []) {
            $this->components->warn('Nobody added. Set ROBOT_COUNCIL_DEVELOPERS in .env before signing in.');

            return [];
        }

        $resolved = [];

        foreach ($logins as $login) {
            // Bounded, and iterating a queue would not be. A `foreach` cannot be extended from
            // inside it -- PHP iterates a copy of the array -- so a retry appended to `$logins`
            // would be written, never read, and the re-prompt would silently do nothing.
            $attempt = $login;

            for ($try = 0; $try < self::MAX_ATTEMPTS && $attempt !== ''; $try++) {
                try {
                    $id = $accounts->id($attempt);
                } catch (AccountLookupFailed $failed) {
                    $this->components->warn($attempt.': '.$failed->getMessage());

                    // A login GitHub has no account for is worth asking about again. One it could
                    // not be asked about is not: the next attempt fails the same way, and
                    // re-prompting somebody whose login was right all along is the worse outcome.
                    $attempt = $failed->unknown
                        ? trim($this->text($this->ask('Try another login, or leave blank to skip', '')))
                        : '';

                    continue;
                }

                $this->line(sprintf('  %s -> %d', $attempt, $id));

                $resolved[$attempt] = $id;

                break;
            }
        }

        if ($resolved === []) {
            $this->components->warn('None resolved. Set ROBOT_COUNCIL_DEVELOPERS in .env before signing in.');

            return [];
        }

        $ids = array_values($resolved);

        return [
            'ROBOT_COUNCIL_DEVELOPERS' => implode(',', array_map(strval(...), $ids)),
            'ROBOT_COUNCIL_ADMINS' => (string) $ids[0],
        ];
    }

    /**
     * The login to offer first, when `gh` is here and signed in.
     *
     * Whoever runs this is almost always the fleet's first developer, and typing one's own login is
     * the kind of small friction that is worth removing when it costs one already-installed tool.
     */
    private function suggestedLogin(): string
    {
        $result = Process::run(['gh', 'api', 'user', '--jq', '.login']);

        return $result->successful() ? trim($result->output()) : '';
    }

    /**
     * Whatever a console call handed back, as text.
     *
     * **It takes `mixed` rather than reading the value itself, and that is the point.** What
     * `Command::argument()` and `Command::ask()` are inferred to return depends on whether the
     * analyzer could boot the application and read this command's signature, which differs between
     * a developer's machine and CI -- so `is_string()` written against one answer is reported as
     * dead code by the other. `mixed` is true on both sides. `RobotCouncil\Console\Argument` in the
     * package exists for the same reason.
     *
     * @param  mixed  $value  Whatever came back.
     * @return string The value as text, or an empty string for anything that is not scalar.
     */
    private function text(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }

    /**
     * Where the application will be served.
     *
     * Defaults to Herd's convention, which is where a Laravel application on a Mac usually is, and
     * is overridden rather than guessed at when it is not.
     *
     * @param  string  $name  The directory that was created.
     */
    private function applicationUrl(string $name): string
    {
        $given = $this->option('app-url');

        return \is_string($given) && $given !== '' ? rtrim($given, '/') : 'http://'.$name.'.test';
    }

    /**
     * Say what was made and what to do with it.
     *
     * @param  string  $name  The directory that was created.
     * @param  string  $url  Where it will be served.
     */
    private function done(string $name, string $url): void
    {
        $this->newLine();
        $this->components->info(sprintf('`%s` is ready.', $name));

        $this->line('  Sign in at       '.rtrim($url, '/').'/robot-council/dashboard');
        $this->line('  Enroll a machine robot-council enroll --service='.rtrim($url, '/'));
        $this->newLine();
    }
}
