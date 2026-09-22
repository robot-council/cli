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

        // **Both a missing item and a failure land here, and whether this backend can tell
        // them apart is unmeasured.** `CredentialStore::get()` says an implementation that
        // can must raise instead; `WindowsCredentialStore` does. `robot-council/cli#54`
        // measures the exit codes this tool actually produces, and this follows from it.
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
