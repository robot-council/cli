<?php

declare(strict_types=1);

namespace App\Commands;

use App\Support\Credentials\Credential;
use App\Support\Credentials\Credentials;
use App\Support\Credentials\CredentialStoreFailed;
use App\Support\DeviceCodeFlow;
use App\Support\MachineIdentity;
use App\Support\Terminal;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Sleep;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;
use Throwable;

/**
 * Enroll this machine, and keep the credential out of every transcript.
 *
 * **This is why enrollment runs here rather than in an agent's own shell.** The credential is a
 * bearer token worth thirty days. Obtained inside a harness, it would land in tool output, and tool
 * output becomes transcript -- so the token would be in the context window of every agent that read
 * back far enough, and in whatever that transcript was later pasted into.
 *
 * So this command prints exactly two things a person needs, the user code and where to approve it,
 * and nothing else. The credential goes straight from the response into a credential store.
 *
 * **It does not put the credential beyond an agent's reach**, and `CredentialStore` says why: an
 * agent running as the same user can read the Keychain or the fallback file if it goes looking.
 * What it cannot do is encounter the token by accident, which is the failure that actually happens.
 */
#[Description('Enroll this machine and store its credential')]
#[Signature('enroll
    {--service= : The service base URL, such as https://fleet.example.com}
    {--harness= : What this machine\'s agent software is called, when detection cannot tell}
    {--machine-label= : What to call this machine in the fleet}
    {--ability=* : An ability to request, repeatable. Defaults to tasks:create, tasks:claim, events:post}')]
final class EnrollCommand extends Command
{
    /**
     * The abilities requested when the developer names none.
     *
     * Deliberately not `coordinator:direct`: that one lets a session's narration reach every other
     * developer's agents, and it should be asked for on purpose rather than arrive by default.
     */
    public const array DEFAULT_ABILITIES = ['tasks:create', 'tasks:claim', 'events:post'];

    /**
     * The longest this waits between polls, whatever interval the service asks for.
     *
     * The service decides the pace, within reason. Without a ceiling, an `interval` of a week is a
     * process that never returns.
     */
    public const int MAX_INTERVAL_SECONDS = 60;

    /**
     * Run the enrollment.
     *
     * @param  Factory  $http  The HTTP client.
     * @param  Credentials  $credentials  Where the credential will be put.
     * @return int The exit code.
     */
    public function handle(Factory $http, Credentials $credentials): int
    {
        $service = $this->resolveService();

        if ($service === null) {
            $this->components->error('Pass --service, or set ROBOT_COUNCIL_SERVICE, with the URL of your fleet.');

            return self::FAILURE;
        }

        $harness = $this->resolveHarness();

        if ($harness === null) {
            $this->components->error('Could not tell what harness this is. Pass --harness, such as --harness=claude.');

            return self::FAILURE;
        }

        $label = MachineIdentity::label($this->stringOption('machine-label'));

        // The verifier stays in this process. Only its hash is sent, and the service stores only a
        // hash of the device code it returns -- so nothing either side keeps can replay this.
        $verifier = bin2hex(random_bytes(32));
        $challenge = hash('sha256', $verifier);

        $flow = new DeviceCodeFlow($http, $service);

        try {
            $requested = $flow->request($harness, $label, $this->resolveAbilities(), $challenge);
        } catch (Throwable $throwable) {
            // `Throwable`, for the same reason the store step catches it: an uncaught exception is
            // rendered by Collision with frame arguments, and this frame holds the PKCE verifier.
            // It is also the ordinary case -- an unreachable service raises `ConnectionException`,
            // which is not a `RuntimeException`, so a typo in `--service` produced a stack trace.
            $this->components->error($this->readable($throwable, 'Could not reach the service.'));

            return self::FAILURE;
        }

        $deviceCode = $this->stringValue($requested['device_code'] ?? null);
        $userCode = $this->stringValue($requested['user_code'] ?? null);
        $verificationUri = $this->stringValue($requested['verification_uri'] ?? null);
        $interval = \is_int($requested['interval'] ?? null) ? $requested['interval'] : 5;
        $expiresIn = \is_int($requested['expires_in'] ?? null) ? $requested['expires_in'] : 600;

        if ($deviceCode === null || $userCode === null || $verificationUri === null) {
            $this->components->error('The service did not return a usable device code.');

            return self::FAILURE;
        }

        // The only two things printed before the outcome. Written with `line()` rather than the
        // styled components so that every word is capturable by a test.
        // Reduced, because both arrived from the service. A `user_code` carrying a carriage
        // return could redraw the line so the code a person reads is not the code they approve.
        $this->line(sprintf('Approve this machine at %s', Terminal::safe($verificationUri)));
        $this->line(sprintf('Your code is %s', Terminal::safe($userCode)));
        $this->newLine();

        $credential = $this->await($flow, $deviceCode, $verifier, $interval, $expiresIn);

        if ($credential === null) {
            return self::FAILURE;
        }

        return $this->store($credentials, $service, $credential);
    }

    /**
     * Poll until the developer decides, or the code dies.
     *
     * @param  DeviceCodeFlow  $flow  The flow.
     * @param  string  $deviceCode  The code being waited on.
     * @param  string  $verifier  The verifier whose hash was sent.
     * @param  int  $interval  Seconds between polls, as the service asked.
     * @param  int  $expiresIn  Seconds the code is good for, as the service said.
     * @return array<string, mixed>|null The credential, or null when it will not arrive.
     */
    private function await(DeviceCodeFlow $flow, string $deviceCode, string $verifier, int $interval, int $expiresIn): ?array
    {
        // Bounded by the service's own expiry and by a backstop, so a service answering
        // `authorization_pending` forever cannot hold this process open indefinitely
        $deadline = time() + min(max($expiresIn, 1), DeviceCodeFlow::MAX_WAIT_SECONDS);

        // Clamped at both ends. The deadline alone does not bound this loop: it is only consulted
        // at the top, so a service answering `authorization_pending` with `interval: 604800` would
        // have held the process open for a week after one exchange.
        $wait = min(max($interval, 1), self::MAX_INTERVAL_SECONDS);

        while (time() < $deadline) {
            try {
                $credential = $flow->exchange($deviceCode, $verifier);
            } catch (Throwable $failure) {
                $this->components->error($this->readable($failure, 'Could not reach the service.'));

                return null;
            }

            if ($credential !== null) {
                return $credential;
            }

            // Through `Sleep` rather than the function, so a test can assert the interval the
            // service asked for was honoured without waiting that long to find out
            // Never past the deadline, so the loop cannot outlive the code it is waiting on
            Sleep::for(min($wait, max($deadline - time(), 1)))->seconds();
        }

        $this->components->error('The code expired before it was approved. Run enroll again.');

        return null;
    }

    /**
     * Put the credential away, and say where it went.
     *
     * @param  Credentials  $credentials  Where the credential will be put.
     * @param  string  $service  The service the credential is for.
     * @param  array<string, mixed>  $credentialBody  What the service returned.
     * @return int The exit code.
     */
    private function store(Credentials $credentials, string $service, array $credentialBody): int
    {
        $token = $this->stringValue($credentialBody['token'] ?? null);

        if ($token === null) {
            $this->components->error('The service approved this machine but returned no credential.');

            return self::FAILURE;
        }

        // `Throwable`, not `CredentialStoreFailed`. An uncaught exception here is rendered by
        // Collision, which prints frame ARGUMENTS -- and this frame holds the credential. Measured
        // before this catch existed: an unwritable config directory made `mkdir()` raise, Laravel's
        // `HandleExceptions` turned that warning into an `ErrorException`, it escaped the narrower
        // catch that used to be here, and the token was printed to stdout verbatim.
        //
        // `Credential` is the structural half of that fix and this is the belt: the object renders
        // as `Object(App\Support\Credentials\Credential)` in any frame, so even a path nobody
        // catches cannot print the value.
        try {
            $store = $credentials->store();

            $store->put($service, new Credential($token));
        } catch (Throwable $throwable) {
            // Only our own exception type's message is repeated. A filesystem or driver message can
            // carry a path, and an unknown one could carry anything at all.
            $this->components->error($this->readable($throwable, 'The credential could not be stored.'));

            return self::FAILURE;
        }

        $granted = $credentialBody['granted_abilities'] ?? [];

        $this->components->info('This machine is enrolled.');
        // The store names itself. `UserFileStore` names its path, which is a deliberate trade:
        // a developer needs to know where their credential is, and an agent that wanted it could
        // find the default location anyway.
        $this->line(sprintf('  Credential stored in %s.', $store->describe()));

        if (\is_array($granted) && $granted !== []) {
            $abilities = array_map(fn (mixed $ability): string => \is_string($ability) ? $ability : '', $granted);

            $this->line(sprintf('  Sessions started here will carry: %s.', implode(', ', array_filter($abilities))));
        }

        return self::SUCCESS;
    }

    /**
     * The service to enroll against.
     */
    private function resolveService(): ?string
    {
        $given = $this->stringOption('service') ?? $this->environmentService();

        if ($given === null) {
            return null;
        }

        $service = rtrim($given, '/');

        // A 30-day bearer token and the PKCE verifier both cross this connection. Over `http://`
        // they cross it in cleartext, and nothing else in this flow would notice.
        if (! str_starts_with($service, 'https://') && ! $this->isLoopback($service)) {
            $this->components->error('The service must be reached over https. Only a loopback address may use http.');

            return null;
        }

        return $service;
    }

    /**
     * A failure in words, without repeating a message this command line did not write.
     *
     * Our own exception types carry messages composed here, so those are safe to print. Anything
     * else -- a driver, the filesystem, the HTTP client -- may carry a path, a URL with credentials
     * in it, or a response body, so only a plain sentence is shown unless `-v` was asked for.
     *
     * @param  Throwable  $failure  What went wrong.
     * @param  string  $fallback  What to say when the message is not ours to repeat.
     */
    private function readable(Throwable $failure, string $fallback): string
    {
        $ours = $failure instanceof CredentialStoreFailed || $failure instanceof RuntimeException;

        $message = $ours ? $failure->getMessage() : $fallback;

        return $this->output->isVerbose()
            ? sprintf('%s (%s: %s)', $message, $failure::class, $failure->getMessage())
            : $message;
    }

    /**
     * Whether a URL points at this machine, where cleartext carries nothing off the host.
     *
     * Allowed so that a developer running `core` locally can enroll against it without a
     * certificate, which is the one case `http://` is not a disclosure.
     *
     * @param  string  $service  The URL as given.
     */
    private function isLoopback(string $service): bool
    {
        $host = parse_url($service, PHP_URL_HOST);

        return \is_string($host) && \in_array($host, ['127.0.0.1', 'localhost', '::1'], true);
    }

    /**
     * The service named in the environment, for a machine that always enrolls against one fleet.
     */
    private function environmentService(): ?string
    {
        $value = getenv('ROBOT_COUNCIL_SERVICE');

        return \is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * What this machine's agent software is called.
     *
     * The flag wins over detection, because a developer correcting a wrong guess should not have to
     * argue with it.
     */
    private function resolveHarness(): ?string
    {
        $given = $this->stringOption('harness');

        if ($given !== null) {
            return MachineIdentity::harness($given);
        }

        $detected = MachineIdentity::detectedHarness();

        return $detected === null ? null : MachineIdentity::harness($detected);
    }

    /**
     * The abilities to request.
     *
     * @return list<string> The abilities.
     */
    private function resolveAbilities(): array
    {
        /** @var array<int, string|null> $given */
        $given = (array) $this->option('ability');

        $named = array_values(array_filter($given, \is_string(...)));

        return $named === [] ? self::DEFAULT_ABILITIES : $named;
    }

    /**
     * One option as a non-empty string, or null.
     *
     * @param  string  $name  The option's name.
     */
    private function stringOption(string $name): ?string
    {
        return $this->stringValue($this->option($name));
    }

    /**
     * A value as a non-empty string, or null.
     *
     * Narrowing here rather than at each call site, for the reason `core`'s `Access\Tokens` gives:
     * a check written for one analyzer's inference is reported as dead code by another.
     *
     * @param  mixed  $value  Whatever was read.
     */
    private function stringValue(mixed $value): ?string
    {
        return \is_string($value) && $value !== '' ? $value : null;
    }
}
