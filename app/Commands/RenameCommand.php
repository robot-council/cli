<?php

declare(strict_types=1);

namespace App\Commands;

use App\Support\Credentials\Credentials;
use App\Support\Credentials\InstallationChoice;
use App\Support\InstallationRename;
use App\Support\Terminal;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Http\Client\Factory;
use LaravelZero\Framework\Commands\Command;
use RuntimeException;
use Throwable;

/**
 * Rename the installation this machine is enrolled as (#359).
 *
 * Fixes a label chosen badly at enrollment, a hostname or `unknown-machine`, without a browser.
 * It runs with the installation credential and starts no session, so a running agent cannot do it
 * on the machine's behalf by way of a session token: the service refuses one.
 *
 * **One credential per harness per fleet**, the same rule `api` applies, so this says which
 * harness it means when more than one is stored and refuses to guess.
 */
#[Description('Rename this machine in the fleet')]
#[Signature('rename
    {label : The new machine label: 1 to 64 characters of A-Z a-z 0-9 . _ -}
    {--service= : The service base URL, defaulting to ROBOT_COUNCIL_SERVICE}
    {--harness= : Which enrolled harness to rename, when detection cannot tell}')]
final class RenameCommand extends Command
{
    /**
     * Rename the installation.
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

        $label = $this->argument('label');

        if ($label === '') {
            $this->components->error('Name the new label, such as `robot-council rename work-laptop`.');

            return self::FAILURE;
        }

        try {
            $installation = new InstallationChoice($credentials)->for($service, $this->stringOption('harness'));
        } catch (RuntimeException $runtimeException) {
            $this->components->error($runtimeException->getMessage());

            return self::FAILURE;
        }

        try {
            $renamed = new InstallationRename($http, $service, $installation)->rename($label);
        } catch (Throwable $throwable) {
            // Only this command's own refusals are repeated. An unreachable service raises a
            // `ConnectionException`, which is not a `RuntimeException`, and its message is not ours
            $this->components->error($throwable instanceof RuntimeException ? $throwable->getMessage() : 'Could not reach the service.');

            return self::FAILURE;
        }

        // Both reduced: they arrived from the service
        $this->line(sprintf('Renamed this machine from %s to %s.', Terminal::safe($renamed['old']), Terminal::safe($renamed['new'])));

        return self::SUCCESS;
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
     * One option as a non-empty string, or null.
     */
    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return \is_string($value) && $value !== '' ? $value : null;
    }
}
