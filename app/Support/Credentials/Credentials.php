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
     * **`WindowsFfiCredentialStore` comes before `WindowsCredentialStore`, and the order is the
     * whole mechanism.** Both reach the same Credential Manager and write interchangeable entries;
     * the first calls `advapi32` in-process and the second starts `powershell.exe` to do it. A
     * machine whose `php.ini` leaves `extension=ffi` commented out -- which is the stock setting --
     * fails the first store's probe and lands on the second, exactly where it was before. A machine
     * that has FFI skips a subprocess per call. Decided on `robot-council/cli#50`.
     *
     * @return list<CredentialStore> The candidates.
     */
    public static function candidates(): array
    {
        return [
            new KeychainStore,
            new SecretToolStore,
            new WindowsFfiCredentialStore,
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
     * @return Credential|null The credential, or null when that harness has none.
     *
     * @throws CredentialStoreFailed When the store's mechanism failed, which is not the same
     *                               answer as holding nothing. Not every store can tell the two
     *                               apart -- see `CredentialStore::get()`.
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
     *
     * @throws CredentialStoreFailed When the store's mechanism failed.
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
     * **And the removal is verified, because `forget()` cannot report failing.** It returns `void`
     * in every implementation and none of them inspects what it ran: `KeychainStore` and
     * `SecretToolStore` discard the exit code deliberately, since a missing item exits non-zero and
     * that is the state being asked for, and `WindowsCredentialStore` discards it too. So a delete
     * that did not happen is indistinguishable from one that did, from here. `put()` above already
     * reads back and compares rather than trusting its own write; this is the same discipline
     * applied to the other half, and #32 is the ticket that asked for it.
     *
     * The claimed credential is **not** rolled back when the check fails. It is correctly stored
     * under the harness that asked, and undoing it would leave a machine with nothing while the
     * legacy entry it could not remove is still there. What is wrong is the leftover, so that is
     * what the message names.
     *
     * @param  string  $service  The fleet's base URL.
     * @param  string  $harness  The harness claiming it.
     * @return Credential|null What was claimed, or null when there was nothing to claim.
     *
     * @throws CredentialStoreFailed When the leftover is still there, or when whether it is still
     *                               there could not be established. Both messages name the claim
     *                               and the leftover, because those are the two facts that decide
     *                               what an operator does next.
     * @throws CredentialStoreFailed When the legacy entry is still readable after being forgotten,
     *                               which would leave it claimable by a second harness.
     */
    public function adopt(string $service, string $harness): ?Credential
    {
        $credential = $this->legacy($service);

        if (! $credential instanceof Credential) {
            return null;
        }

        $store = $this->store();

        $legacy = CredentialKey::legacy($service);

        $store->put(CredentialKey::for($service, $harness), $credential);
        $store->forget($legacy);

        // Read back rather than trust the delete. On a store whose backend refused -- an antivirus
        // product holding the script, a policy change mid-session, a locked keychain -- this is the
        // only thing between a silent success and two harnesses presenting one installation's
        // credential.
        // **Rewrapped rather than allowed out.** `WindowsCredentialStore::get()` now raises when
        // its mechanism is broken, and that message names the mechanism -- true, and the wrong two
        // facts for this moment. What an operator needs here is that the credential WAS claimed,
        // and that a leftover has to go by hand before another harness claims it too.
        try {
            $leftover = $store->get($legacy) instanceof Credential;
        } catch (CredentialStoreFailed $credentialStoreFailed) {
            throw new CredentialStoreFailed(sprintf(
                'The credential was claimed for `%s`, but whether the one stored before harnesses were told apart is gone could not be checked: %s '
                .'Check %s by hand before another harness claims it too.',
                $harness,
                $credentialStoreFailed->getMessage(),
                $store->describe()
            ), $credentialStoreFailed->getCode(), $credentialStoreFailed);
        }

        if ($leftover) {
            // Both sentences are asserted, so dropping either fails a test. Their ORDER is not:
            // swapping them leaves a message carrying the same two facts, and pinning the order
            // would make the test brittle to rewording while protecting nothing.
            // @pest-mutate-ignore: ConcatSwitchSides
            throw new CredentialStoreFailed(sprintf(
                'The credential was claimed for `%s`, but the one stored before harnesses were told apart could not be removed from %s. '
                .'Remove it by hand before another harness claims it too.',
                $harness,
                $store->describe()
            ));
        }

        return $credential;
    }

    /**
     * Which of the given harnesses have a credential stored for one fleet.
     *
     * **Probed one at a time, because no store can list its keys.** That is a subprocess apiece on
     * a keychain, so this is for a path that is already refusing and never for a hot one.
     *
     * **The result is a lower bound.** A harness whose read fails is reported as not stored, so a
     * machine whose credential store has broken mid-session lists fewer harnesses rather than
     * failing to answer. That is the opposite of what `get()` does for a caller that wants a
     * specific credential, and the difference is the point: this runs while the process is already
     * refusing, and a diagnostic that aborts the diagnosis is worse than an incomplete one.
     *
     * @param  string  $service  The fleet's base URL.
     * @param  list<string>  $harnesses  The harnesses to ask about.
     * @return list<string> Those that have one, in the order given; a lower bound.
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
            // **A broken mechanism is treated as "not listed" here, deliberately, and only here.**
            // `WindowsCredentialStore::get()` now raises rather than reporting a fault as "no
            // credential", which is right where a caller wants a specific credential. This is not
            // that: it is a best-effort diagnostic assembled while the process is already refusing,
            // and its own caller documents the result as a lower bound. Letting the throw out would
            // discard a partial but true list and replace a refusal that names a remedy with one
            // that names none -- including on the path where no harness resolved at all and the
            // store is irrelevant to the operator's actual problem.
            static function (string $harness) use ($store, $service): bool {
                try {
                    return $store->get(CredentialKey::for($service, $harness)) instanceof Credential;
                } catch (CredentialStoreFailed) {
                    return false;
                }
            }
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
