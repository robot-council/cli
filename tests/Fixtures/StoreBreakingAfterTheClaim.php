<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Support\Credentials\Credential;
use App\Support\Credentials\CredentialStore;
use App\Support\Credentials\CredentialStoreFailed;

/**
 * A store that answers the legacy key once and then stops answering.
 *
 * **The read count is what makes this a mid-claim failure rather than another one.** `adopt()`
 * reads the legacy key twice: once through `legacy()`, which has to answer or there is nothing to
 * claim at all, and once to verify the delete took. A store that refused both reads would exercise
 * a different path entirely -- nothing claimed, nothing to warn anybody about. Refusing only the
 * second puts the store in the state that branch exists for: **the credential HAS been claimed**,
 * and whether the leftover is gone can no longer be established.
 *
 * Named rather than anonymous, unlike the older fakes in `CredentialKeyTest`, because its public
 * `$reads` and `$entries` are asserted on and PHPStan cannot see them through a helper declared to
 * return `CredentialStore`.
 */
final class StoreBreakingAfterTheClaim implements CredentialStore
{
    /**
     * @var array<string, string> What this store holds, keyed by credential key.
     */
    public array $entries = [];

    /**
     * @var int How many times `get()` has been called, across all keys.
     */
    public int $reads = 0;

    /**
     * @param  bool  $breaks  Whether the read-back raises. False is the control, and is the only
     *                        difference between two instances of this class.
     */
    public function __construct(private readonly bool $breaks = true) {}

    public function available(): bool
    {
        return true;
    }

    public function describe(): string
    {
        return 'a store that stops answering mid-claim';
    }

    public function put(string $service, Credential $credential): void
    {
        $this->entries[$service] = $credential->reveal();
    }

    public function get(string $service): ?Credential
    {
        $this->reads++;

        if ($this->breaks && $this->reads > 1) {
            throw new CredentialStoreFailed('the keychain is locked');
        }

        return isset($this->entries[$service]) ? new Credential($this->entries[$service]) : null;
    }

    public function forget(string $service): void
    {
        unset($this->entries[$service]);
    }
}
