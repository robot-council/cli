<?php

declare(strict_types=1);

namespace App\Support\Credentials;

use Random\RandomException;

/**
 * The Credential Manager target a service key is filed under, and the ceiling on what may go in it.
 *
 * **This is a class of its own because two stores have to agree on it byte for byte.**
 * `WindowsFfiCredentialStore` and `WindowsCredentialStore` reach the same Credential Manager by
 * different mechanisms, and which one a machine uses depends on its `php.ini`. If the two derived
 * targets independently, a machine that gained or lost FFI would stop being able to read what it
 * had already stored -- and nothing would report it, because a target nobody wrote to is
 * indistinguishable from a machine that was never enrolled. One implementation makes that drift
 * impossible rather than unlikely; a test then asserts the two stores return identical targets, so
 * even a future fork of this logic fails a gate.
 *
 * **The digest is not decoration: Credential Manager matches target names case-insensitively, and
 * the key is case-sensitive everywhere else.** Measured on 2026-09-21 against `advapi32.dll` through
 * PowerShell, and again on 2026-09-22 through FFI, with a key stored under one casing and read back
 * under another:
 *
 * ```
 * put('https://example.test/FleetA|claude')
 *   get('https://example.test/FleetA|claude') -> 'TOKEN-FOR-MIXED-CASE'
 *   get('https://example.test/fleeta|claude') -> 'TOKEN-FOR-MIXED-CASE'   <- never enrolled
 *   get('https://example.test/NothingHere|claude') -> null                <- control
 * ```
 *
 * Both mechanisms collapse the middle row, because the behavior is `advapi32`'s rather than either
 * caller's. `UserFileStore` returns null for it, so without the digest the Windows stores alone
 * would hand one fleet's bearer token to a different fleet whose URL differs only in case -- and
 * `put()`'s read-back is structurally blind to it, because it reads back the very entry it
 * collapsed onto. Appending a case-sensitive digest of the exact key makes two keys that differ
 * only in case land on two targets. The key stays in the target in readable form, so
 * `cmdkey /list` is still greppable.
 */
final class WindowsCredentialTarget
{
    /**
     * The prefix every credential is filed under, so the targets these stores own are greppable in
     * `cmdkey /list` and cannot collide with anything else on the machine.
     */
    public const string PREFIX = 'robot-council:';

    /**
     * The prefix a probe target is built under, completed with fresh randomness every time.
     *
     * **It deliberately does not begin with `PREFIX`.** A service key is an opaque string that
     * nothing here parses, so every target `for()` can produce is `PREFIX` followed by *anything* --
     * including a leading colon. A probe target sharing that prefix could therefore be occupied by a
     * real credential, and a store's `available()` would read someone's stored token as a broken
     * mechanism.
     *
     * **The random suffix closes a second hole.** A probe requires "no such credential", so a fixed
     * target is something anything running as this user could occupy -- one
     * `cmdkey /generic:<the fixed target>` and `available()` reports false forever, silently
     * downgrading the machine to `UserFileStore`, which on Windows checks no permissions at all. A
     * target nobody can predict cannot be squatted.
     */
    public const string PROBE_PREFIX = 'robot-council-probe:';

    /**
     * The largest blob `CredWriteW` accepts, in bytes.
     *
     * `CRED_MAX_CREDENTIAL_BLOB_SIZE`. Checked before the call so that an over-long credential fails
     * saying so, rather than through a read-back that only reports the value did not land.
     *
     * Measured from both sides, through both mechanisms. Through PowerShell on 2026-09-21 and
     * through FFI on 2026-09-22: 2,560 bytes stores and reads back, 2,561 is refused inside
     * `CredWriteW` itself -- with Windows error 1783 on the FFI path, which is the first place that
     * code was legible rather than collapsed into a non-zero exit.
     */
    public const int MAX_BLOB_BYTES = 2560;

    /**
     * The target one service key is filed under.
     *
     * @param  string  $service  The credential key, which nothing here parses.
     * @return string The target name.
     */
    public static function for(string $service): string
    {
        return sprintf('%s%s#%s', self::PREFIX, $service, substr(hash('sha256', $service), 0, 16));
    }

    /**
     * A target nothing is filed at, for a store to prove its mechanism reaches `CredReadW`.
     *
     * **`random_bytes()` can raise**, and a caller using this to answer `available()` must treat
     * that as "not available" rather than letting it out. Both stores wrap the call for that reason.
     *
     * @return string A target no credential can be stored at.
     *
     * @throws RandomException When the machine has no usable source of randomness.
     */
    public static function probe(): string
    {
        return self::PROBE_PREFIX.bin2hex(random_bytes(16));
    }
}
