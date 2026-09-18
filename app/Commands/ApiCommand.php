<?php

declare(strict_types=1);

namespace App\Commands;

use App\Support\Credentials\Credential;
use App\Support\Credentials\Credentials;
use App\Support\Session;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Http\Client\Factory;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;
use Throwable;

/**
 * One call to the coordination service, inside a session this command starts and ends.
 *
 * The fallback for harnesses without MCP, and the smallest thing that exercises a whole session:
 * start, use, end. The bridge in #5 renews tokens, handles signals, and must keep stdout free of
 * anything that is not a protocol message -- it should inherit a session lifecycle already covered
 * by tests rather than grow one alongside all of that.
 *
 * **Only the response body reaches stdout.** A harness may pipe this straight into an agent, so the
 * session token, the installation credential, and every diagnostic go to stderr or nowhere.
 */
#[Description('Perform one API call inside a session this command starts and ends')]
#[Signature('api
    {method : The HTTP method, such as GET or POST}
    {path : The path, such as /api/tasks}
    {--service= : The service base URL, defaulting to ROBOT_COUNCIL_SERVICE}
    {--body= : A JSON body, for methods that take one}
    {--project= : The repository or workspace this call belongs to}')]
final class ApiCommand extends Command
{
    /**
     * Perform the call.
     *
     * @param  Factory  $http  The HTTP client.
     * @param  Credentials  $credentials  Where the installation credential lives.
     * @return int The exit code.
     */
    public function handle(Factory $http, Credentials $credentials): int
    {
        $service = $this->resolveService();

        if ($service === null) {
            $this->components->error('Pass --service, or set ROBOT_COUNCIL_SERVICE, with the URL of your fleet.');

            return self::FAILURE;
        }

        $installation = $credentials->store()->get($service);

        if (! $installation instanceof Credential) {
            $this->components->error('This machine is not enrolled against that service. Run `robot-council enroll`.');

            return self::FAILURE;
        }

        $body = $this->decodeBody();

        if ($body === false) {
            $this->components->error('--body must be valid JSON.');

            return self::FAILURE;
        }

        $session = new Session($http, $service, $installation);

        try {
            $session->start($this->stringOption('project'));
        } catch (Throwable $throwable) {
            $this->components->error($this->readable($throwable, 'Could not start a session.'));

            return self::FAILURE;
        }

        // `finally`, not "after the call". The request failing is exactly the case where leaving a
        // session open costs the most: `core` releases a session's tasks and locks when it ends,
        // and otherwise waits for the presence sweep -- so an unclosed session leaves the fleet
        // holding work nobody is doing.
        try {
            return $this->perform($session, $service, $body);
        } catch (Throwable $throwable) {
            $this->components->error($this->readable($throwable, 'The call did not complete.'));

            return self::FAILURE;
        } finally {
            $session->end();
        }
    }

    /**
     * Make the request and print what came back.
     *
     * @param  Session  $session  The started session.
     * @param  string  $service  The service's base URL.
     * @param  array<string, mixed>|null  $body  The decoded request body, when there is one.
     * @return int The exit code.
     */
    private function perform(Session $session, string $service, ?array $body): int
    {
        $method = strtoupper($this->stringArgument('method') ?? 'GET');
        $path = '/'.ltrim($this->stringArgument('path') ?? '', '/');

        $response = $session->request()->send($method, $service.$path, $body === null ? [] : ['json' => $body]);

        // The body on stdout, so a harness can pipe it. Nothing else goes there.
        $this->output->writeln((string) $response->body());

        if ($response->successful()) {
            return self::SUCCESS;
        }

        // The status on stderr, so it does not corrupt the body a caller is parsing
        $this->components->error(sprintf('The service answered HTTP %d.', $response->status()));

        return self::FAILURE;
    }

    /**
     * The request body, or false when it was given and is not JSON.
     *
     * @return array<string, mixed>|null|false The body, null when none was given, false when bad.
     */
    private function decodeBody(): array|null|false
    {
        $given = $this->stringOption('body');

        if ($given === null) {
            return null;
        }

        try {
            $decoded = json_decode($given, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }

        if (! \is_array($decoded)) {
            return false;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * The service to call.
     */
    private function resolveService(): ?string
    {
        $given = $this->stringOption('service');

        if ($given === null) {
            $fromEnvironment = getenv('ROBOT_COUNCIL_SERVICE');

            $given = \is_string($fromEnvironment) && $fromEnvironment !== '' ? $fromEnvironment : null;
        }

        return $given === null ? null : rtrim($given, '/');
    }

    /**
     * A failure in words, without repeating a message this command line did not write.
     *
     * @param  Throwable  $failure  What went wrong.
     * @param  string  $fallback  What to say when the message is not ours to repeat.
     */
    private function readable(Throwable $failure, string $fallback): string
    {
        $message = $failure instanceof RuntimeException ? $failure->getMessage() : $fallback;

        return $this->output->isVerbose()
            ? sprintf('%s (%s: %s)', $message, $failure::class, $failure->getMessage())
            : $message;
    }

    /**
     * One argument as a non-empty string, or null.
     */
    private function stringArgument(string $name): ?string
    {
        $value = $this->argument($name);

        return \is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * One option as a non-empty string, or null.
     */
    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return \is_string($value) && $value !== '' ? $value : null;
    }
}
