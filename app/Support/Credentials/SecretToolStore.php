<?php

declare(strict_types=1);

namespace App\Support\Credentials;

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The freedesktop Secret Service, through `secret-tool`.
 *
 * `secret-tool store` reads the secret from stdin by design, so unlike the macOS path there is no
 * argv exposure to work around. It takes attribute pairs rather than a service and account, and the
 * pair below is what `lookup` and `clear` match on.
 *
 * **Not measured.** No Linux machine was available when this was written, and the behavior is read
 * from `secret-tool`'s documentation rather than observed. The read-back in `put()` is what makes
 * that survivable: if any of this is wrong, enrollment fails loudly rather than reporting success
 * and storing nothing.
 */
final class SecretToolStore implements CredentialStore
{
    /**
     * The attribute every item is filed under.
     */
    public const string ATTRIBUTE = 'robot-council';

    /**
     * How long any one `secret-tool` call may take.
     *
     * A headless Linux box with no unlocked keyring can leave the Secret Service waiting, which
     * would otherwise hang enrollment with no indication why.
     */
    public const int TIMEOUT_SECONDS = 15;

    public function available(): bool
    {
        return PHP_OS_FAMILY === 'Linux' && (new ExecutableFinder)->find('secret-tool') !== null;
    }

    public function describe(): string
    {
        return sprintf('the Secret Service, under the attribute `%s`', self::ATTRIBUTE);
    }

    public function put(string $service, Credential $credential): void
    {
        $token = $credential->reveal();

        $write = new Process(
            ['secret-tool', 'store', '--label=robot-council', self::ATTRIBUTE, $service],
            timeout: self::TIMEOUT_SECONDS,
        );

        $write->setInput($token);
        $write->run();

        // Read back rather than trusting the exit code, for the same reason the Keychain store does:
        // a store that reported success and holds nothing is the failure that costs the most later
        $stored = $this->get($service);

        if (! $stored instanceof Credential || ! $stored->equals($credential)) {
            throw new CredentialStoreFailed('The credential could not be stored in the Secret Service.');
        }
    }

    public function get(string $service): ?Credential
    {
        $read = new Process(
            ['secret-tool', 'lookup', self::ATTRIBUTE, $service],
            timeout: self::TIMEOUT_SECONDS,
        );

        $read->run();

        // **Both a missing item and a failure land here, because this backend cannot tell them
        // apart.** `CredentialStore::get()` says an implementation that can must raise instead;
        // `WindowsCredentialStore` and `KeychainStore` do. Measured for #85 on Ubuntu 24.04.5,
        // libsecret-tools 0.21.4 and gnome-keyring 46.1: a present item exits 0 (the control), an
        // absent one exits 1, and so does every failure produced -- a dead bus address, no bus at
        // all, a locked collection, and a daemon restarted locked. Only a usage error differs (2).
        //
        // **A locked keyring reads as absent, and silently.** The locked lookups wrote nothing to
        // stderr, so a stored credential behind a locked collection is indistinguishable from none.
        // The bus failures did write to stderr, but a rule keyed on stderr would catch only them,
        // and whether a genuine miss is always silent there has not been measured across versions.
        if (! $read->isSuccessful()) {
            return null;
        }

        // No trim: `secret-tool lookup` writes the secret with no trailing newline, and a credential
        // is opaque, so trimming could silently corrupt one that legitimately ended in whitespace
        $value = $read->getOutput();

        return $value === '' ? null : new Credential($value);
    }

    public function forget(string $service): void
    {
        $clear = new Process(
            ['secret-tool', 'clear', self::ATTRIBUTE, $service],
            timeout: self::TIMEOUT_SECONDS,
        );

        $clear->run();
    }
}
