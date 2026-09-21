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
     *
     * @param  string  $service  The fleet's base URL.
     * @param  string  $harness  The harness being enrolled.
     * @param  Credential  $credential  What to store.
     */
    public function put(string $service, string $harness, Credential $credential): void
    {
        $this->store()->put(CredentialKey::for($service, $harness), $credential);
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
     * re-enrolling.
     *
     * **The legacy entry is removed once it is claimed**, because a credential belongs to one
     * installation. Leaving it would let a second harness claim the same one, and two harnesses
     * presenting one installation's credential is exactly the confusion harness keying exists to
     * end.
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

        $store = $this->store();

        $store->put(CredentialKey::for($service, $harness), $credential);
        $store->forget(CredentialKey::legacy($service));

        return $credential;
    }

    /**
     * Which of the given harnesses have a credential stored for one fleet.
     *
     * **Probed one at a time, because no store can list its keys.** That is a subprocess apiece on
     * a keychain, so this is for a path that is already refusing and never for a hot one.
     *
     * @param  string  $service  The fleet's base URL.
     * @param  list<string>  $harnesses  The harnesses to ask about.
     * @return list<string> Those that have one, in the order given.
     */
    public function storedAmong(string $service, array $harnesses): array
    {
        $store = $this->store();

        // `array_values` is load-bearing for the DECLARED type rather than for this method's one
        // caller, which does `implode()` and cannot tell the difference. `array_filter` preserves
        // keys, so dropping it returns `array<int<0, max>, string>` where the signature promises
        // `list<string>` -- measured: `composer analyse` fails on exactly that, and the suite stays
        // green. Killing this mutant with a test would duplicate a check another gate already makes
        // better, so it is annotated instead, which is what the survivor criterion asks for.
        //
        // The marker carries no prose on its own line: v5.0.2 captures the rest of that line and
        // compares it against mutator names, so a trailing explanation silently suppresses nothing.
        // @pest-mutate-ignore: UnwrapArrayValues
        return array_values(array_filter(
            $harnesses,
            static fn (string $harness): bool => $store->get(CredentialKey::for($service, $harness)) instanceof Credential
        ));
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
