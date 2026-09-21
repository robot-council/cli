<?php

declare(strict_types=1);

namespace App\Support\Credentials;

/**
 * The credential store this machine should use, and the one place that decides.
 *
 * Stores are tried in order and the first available one wins, with `UserFileStore` last because it
 * is the only one that is always available. Resolving here rather than at each call site means a
 * machine that gains `secret-tool` starts using it without anything else changing.
 *
 * **It also owns the key**, which is why callers pass a fleet and a harness rather than a string.
 * `robot-council/cli#21` decided one credential per harness per fleet, and building that key here
 * rather than at each call site is what leaves every `CredentialStore` -- including the Windows one
 * that does not exist yet -- unchanged.
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

    /**
     * Store one harness's credential for one fleet, replacing whatever that harness had.
     *
     * **Written under the legacy bare-fleet key as well, for now.** No store can list its keys, so
     * a reader that does not know which harness to ask for cannot find a composite one -- and
     * `mcp` and `api` do not know yet, because choosing is `robot-council/cli#24`. Until it lands,
     * the bare key is what keeps them working exactly as they do today, and this change is
     * behavior-neutral rather than half a migration. #24 removes this second write.
     *
     * @param  string  $service  The fleet's base URL.
     * @param  string  $harness  The harness being enrolled.
     * @param  Credential  $credential  What to store.
     */
    public function put(string $service, string $harness, Credential $credential): void
    {
        $store = $this->store();

        $store->put(CredentialKey::for($service, $harness), $credential);
        $store->put(CredentialKey::legacy($service), $credential);
    }

    /**
     * One harness's credential for one fleet, or null when that harness has none.
     *
     * Deliberately does not fall back to the legacy key. A credential stored before harnesses were
     * distinguished belongs to whichever harness enrolled it, and handing it to whoever asks first
     * is the inference `robot-council/cli#21` refused. `adopt()` is how it is claimed, by an act.
     *
     * @param  string  $service  The fleet's base URL.
     * @param  string  $harness  The harness asking.
     * @return Credential|null The credential, or null.
     */
    public function get(string $service, string $harness): ?Credential
    {
        return $this->store()->get(CredentialKey::for($service, $harness));
    }

    /**
     * The credential a machine enrolled before harness keying left behind, if there is one.
     *
     * @param  string  $service  The fleet's base URL.
     * @return Credential|null The credential, or null.
     */
    public function legacy(string $service): ?Credential
    {
        return $this->store()->get(CredentialKey::legacy($service));
    }

    /**
     * Claim a legacy credential for one harness, and return it.
     *
     * The one-time act a machine enrolled before `robot-council/cli#23` performs instead of
     * re-enrolling. The legacy entry is left in place rather than removed, because until #24 lands
     * it is still what `mcp` and `api` read; #24 removes both it and the second write above.
     *
     * @param  string  $service  The fleet's base URL.
     * @param  string  $harness  The harness claiming it.
     * @return Credential|null What was claimed, or null when there was nothing to claim.
     */
    public function adopt(string $service, string $harness): ?Credential
    {
        $credential = $this->legacy($service);

        if (! $credential instanceof Credential) {
            return null;
        }

        $this->store()->put(CredentialKey::for($service, $harness), $credential);

        return $credential;
    }

    /**
     * Forget one harness's credential for one fleet, leaving every other harness alone.
     *
     * @param  string  $service  The fleet's base URL.
     * @param  string  $harness  The harness to forget.
     */
    public function forget(string $service, string $harness): void
    {
        $this->store()->forget(CredentialKey::for($service, $harness));
    }
}
