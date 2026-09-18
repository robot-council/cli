<?php

declare(strict_types=1);

namespace App\Support\Credentials;

use Symfony\Component\Process\Process;

/**
 * Windows Credential Manager, through PowerShell's `CredentialManager` surface.
 *
 * **Not measured, and deliberately conservative.** No Windows machine was available when this was
 * written. `cmdkey /generic:… /pass:…` is the obvious approach and is rejected: it puts the token in
 * argv, which is the exposure the macOS store goes out of its way to avoid, and Windows has no
 * stdin equivalent for it.
 *
 * So this reports itself **unavailable** rather than storing a credential badly. On Windows the
 * `UserFileStore` fallback takes over, which is a file only the user can read -- weaker than a real
 * credential store, stronger than a token in every process list on the machine. Implementing this
 * properly is its own ticket, and one that needs a Windows box to verify rather than reason about.
 */
final class WindowsCredentialStore implements CredentialStore
{
    public function available(): bool
    {
        return false;
    }

    public function describe(): string
    {
        return 'Windows Credential Manager (not implemented)';
    }

    public function put(string $service, Credential $credential): void
    {
        throw new CredentialStoreFailed('Windows Credential Manager is not implemented.');
    }

    public function get(string $service): ?Credential
    {
        return null;
    }

    public function forget(string $service): void
    {
        //
    }
}
