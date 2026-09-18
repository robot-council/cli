<?php

declare(strict_types=1);

namespace App\Support\Credentials;

use Symfony\Component\Process\Process;

/**
 * The macOS Keychain, through `security`.
 *
 * **The token is written on stdin, never as an argument.** `security`'s own usage text says so:
 * "Use of the -p or -w options is insecure." A value passed as `-w <token>` sits in the process's
 * argv, which any local process can read with `ps` for as long as the call runs. Passing `-w` last
 * and empty makes it prompt instead, and the prompt reads stdin.
 *
 * Measured on macOS 26.6.2 on 2026-09-18, and both details cost a round of debugging:
 *
 * - The prompt asks to **retype**, so the value goes in twice. One line answers
 *   `passwords don't match`.
 * - It **exits 0 even when the passwords did not match**, so the exit code proves nothing here.
 *   `put()` therefore reads the value back and compares, which is the only check that discriminates.
 */
final class KeychainStore implements CredentialStore
{
    /**
     * The Keychain service name every item is filed under.
     */
    public const string SERVICE = 'robot-council';

    /**
     * How long any one `security` call may take.
     *
     * Bounded because the write path deliberately uses an interactive prompt, and a prompt that
     * stops reading stdin would otherwise hang a developer's terminal with no indication why.
     */
    public const int TIMEOUT_SECONDS = 15;

    public function available(): bool
    {
        return PHP_OS_FAMILY === 'Darwin' && is_executable('/usr/bin/security');
    }

    public function describe(): string
    {
        return sprintf('the macOS Keychain, under the service `%s`', self::SERVICE);
    }

    public function put(string $service, Credential $credential): void
    {
        $token = $credential->reveal();

        // `-U` updates rather than refusing when an item already exists, so re-enrolling against
        // the same service replaces the credential instead of erroring
        $write = new Process(
            ['/usr/bin/security', 'add-generic-password', '-a', $service, '-s', self::SERVICE, '-U', '-w'],
            timeout: self::TIMEOUT_SECONDS,
        );

        // Twice, for the retype prompt
        $write->setInput($token."\n".$token."\n");
        $write->run();

        // Not `$write->isSuccessful()`: it exits 0 on a mismatch. The read-back is the decision.
        $stored = $this->get($service);

        if (! $stored instanceof Credential || ! $stored->equals($credential)) {
            throw new CredentialStoreFailed('The credential could not be stored in the macOS Keychain.');
        }
    }

    public function get(string $service): ?Credential
    {
        $read = new Process(
            ['/usr/bin/security', 'find-generic-password', '-a', $service, '-s', self::SERVICE, '-w'],
            timeout: self::TIMEOUT_SECONDS,
        );

        $read->run();

        if (! $read->isSuccessful()) {
            return null;
        }

        $value = trim($read->getOutput());

        return $value === '' ? null : new Credential($value);
    }

    public function forget(string $service): void
    {
        $delete = new Process(
            ['/usr/bin/security', 'delete-generic-password', '-a', $service, '-s', self::SERVICE],
            timeout: self::TIMEOUT_SECONDS,
        );

        // A missing item exits non-zero, which is the state being asked for rather than a failure
        $delete->run();
    }
}
