<?php

declare(strict_types=1);

namespace App\Support\Credentials;

/**
 * A store that can answer several keys in one go.
 *
 * **Optional, and separate from `CredentialStore` deliberately.** A method on that interface would
 * oblige all five implementors, so the stores that gain nothing from batching would each carry a
 * loop, and `WindowsCredentialStore` -- the one store this exists for -- would have to be edited
 * twice, once to satisfy the interface and once to override it. A capability a store declares when
 * it has something to declare costs the other four nothing. Decided on `robot-council/cli#34`.
 *
 * **What this buys is one process, not one query.** `Credentials::storedAmong()` asks about every
 * known harness while the command line is already refusing, and on a store whose reads are
 * subprocesses that is fifteen of them. Measured for #31 on Windows: 11,656ms serial against 974ms
 * batched, because the cost is the single `Add-Type` compile rather than the reads. A store that
 * reads in-process -- `WindowsFfiCredentialStore`, `UserFileStore` -- has nothing to win and
 * should not implement this.
 */
interface ReadsManyCredentials
{
    /**
     * The credentials for several keys, in one call.
     *
     * **The map is keyed by the strings that were PASSED IN, byte for byte, and on the one store
     * this exists for those are not the strings the store looks things up by.**
     * `WindowsCredentialStore` never addresses Credential Manager by a credential key: it maps each
     * one through `WindowsCredentialTarget::for()` first, yielding
     * `robot-council:<key>#<16-hex-digest>`. So a batch that answers in target-name space returns a
     * map whose every key misses, `storedAmong()` reports nothing stored on a fully enrolled
     * machine, and `remedies()` tells somebody who has already enrolled to run `enroll` first.
     *
     * **Nothing detects that.** The answer is well-formed, the call succeeded, and an empty result
     * is what a machine with no credentials returns too. The translation back to the caller's key
     * is the implementation's job, and it is stated here because it is the one part of this
     * contract that cannot be inferred from the signature.
     *
     * A second edge in the same place: Credential Manager matches target names
     * case-insensitively -- which is why `for()` carries a digest at all -- while the caller
     * resolves this map with `isset()`, which does not. An implementation that echoes back what the
     * backend reported rather than what it was asked for can differ in case and miss.
     *
     * **A key that could not be read is ABSENT from the result, never an exception.** That is the
     * opposite of `CredentialStore::get()`, which raises when it can tell its mechanism is broken
     * (#39), and the difference is not an oversight.
     *
     * The caller is `storedAmong()`, which runs while the process is already refusing and
     * documents its answer as a lower bound: it wants as many harnesses as can be named, not a
     * diagnosis. Today it catches per key, so one unreadable key costs that key alone. **A batch
     * read that threw would collapse that granularity**, taking every key with it where a loop
     * takes one -- and no test in this suite could see the difference, because the two behave
     * identically when every key fails and only diverge when some do.
     *
     * So an implementation that cannot read one key omits it and answers for the rest. An
     * implementation that cannot run at all is free to throw, because there is nothing to answer
     * with, and the caller treats that the same way it treats a store that found nothing.
     *
     * @param  list<string>  $services  The credential keys, as `CredentialKey::for()` builds them.
     * @return array<string, Credential> The keys that have a credential, keyed by the key. Keys
     *                                   with none, and keys that could not be read, are absent.
     */
    public function getMany(array $services): array;
}
