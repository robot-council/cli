<?php

declare(strict_types=1);

namespace App\Support\Credentials;

/**
 * The credential store this machine should use, and the one place that decides.
 *
 * Stores are tried in order and the first available one wins, with `UserFileStore` last because it
 * is the only one that is always available. Resolving here rather than at each call site means a
 * machine that gains `secret-tool` starts using it without anything else changing.
 */
final class Credentials
{
    /**
     * @param  list<CredentialStore>  $stores  The stores to try, in order.
     */
    public function __construct(private readonly array $stores) {}

    /**
     * The stores this machine should try, in order, on any platform.
     *
     * @return list<CredentialStore> The candidates.
     */
    public static function candidates(): array
    {
        return [
            new KeychainStore,
            new SecretToolStore,
            new WindowsCredentialStore,
            new UserFileStore,
        ];
    }

    /**
     * The first store that can be used on this machine.
     *
     * Never null: `UserFileStore` reports itself available everywhere, which is what makes it the
     * fallback rather than one more thing that can be missing.
     */
    public function store(): CredentialStore
    {
        foreach ($this->stores as $store) {
            if ($store->available()) {
                return $store;
            }
        }

        // Reached only if somebody removes the file store from the candidates, which would be a
        // programming error rather than a machine's configuration
        throw new CredentialStoreFailed('No credential store is available, not even the file fallback.');
    }
}
