<?php

declare(strict_types=1);

namespace App\Support\Credentials;

/**
 * Where an installation credential lives between runs.
 *
 * **Be precise about what this buys, because the obvious claim is false.** None of these stores
 * puts the credential beyond an agent's reach. The macOS Keychain trusts the application that
 * created an item -- which is `/usr/bin/security` itself, so any later `security
 * find-generic-password -w` reads it back without a prompt; that is exactly how `put()` verifies
 * its own write. The file store is mode 0600 in the user's own home, so anything running as that
 * user reads it with `cat`. An agent with shell access, running as the developer, can reach every
 * one of them.
 *
 * What this design actually delivers is narrower and still worth having: **the credential never
 * enters a transcript.** It is obtained by a command a human runs, and it is never printed, never
 * passed as a command-line argument, and never written where a repository would pick it up. An
 * agent would have to go looking; it will not encounter the token by reading back through its own
 * context.
 *
 * Putting a credential genuinely beyond a same-user process needs something this command line
 * cannot provide on its own -- a keychain ACL naming one binary, or a separate user. If that is
 * ever wanted, it is a different design rather than a stricter implementation of this one.
 *
 * Keyed by the service's base URL, so a machine enrolled against two deployments holds two
 * credentials rather than overwriting one with the other.
 */
interface CredentialStore
{
    /**
     * Whether this store can be used on this machine right now.
     *
     * Checked rather than assumed from the operating system: a Linux box without `secret-tool`
     * installed is Linux all the same, and falling back is better than failing.
     */
    public function available(): bool;

    /**
     * Where a developer would look for what this store wrote, in words.
     *
     * Printed after a successful enrollment, so that "it is stored" is a claim a person can check
     * rather than take on faith.
     */
    public function describe(): string;

    /**
     * Store the credential for one service.
     *
     * @param  string  $service  The service's base URL, which is the key.
     * @param  Credential  $credential  The credential. Implementations must keep it out of argv.
     *
     * @throws CredentialStoreFailed When the value could not be stored and read back.
     */
    public function put(string $service, Credential $credential): void;

    /**
     * The credential for one service, or null when none is stored.
     *
     * **Null means "nothing is stored", and an implementation that can tell a broken mechanism
     * apart from that must raise rather than return null for it.** Decided on
     * `robot-council/cli#39`. The two are genuinely different answers: reported as "not enrolled",
     * the remedy an operator reaches for is to enroll again, which asks the service for a new
     * credential and stores it through the same broken mechanism -- creating an installation on
     * the way and never stating the fault.
     *
     * `CredentialStoreFailed` is a `RuntimeException`, and `ApiCommand` and `McpCommand` wrap the
     * credential resolution in a catch for it, so raising costs a caller nothing.
     *
     * **Only `WindowsCredentialStore` does this today, and that is a measurement gap rather than a
     * decision.** Its helper defines a distinct exit code for "no such credential", so it can tell
     * them apart. Whether `security` and `secret-tool` can is unmeasured -- `robot-council/cli#54`
     * is where that is settled, and every store that turns out to be able to will follow this one.
     * `UserFileStore` reads a JSON file and deliberately treats a corrupt one as empty, so a
     * developer can re-enroll rather than hand-edit; whether that deserves the same treatment is a
     * judgment recorded there too.
     *
     * @param  string  $service  The service's base URL.
     *
     * @throws CredentialStoreFailed When the store could tell that its mechanism failed, as
     *                               opposed to holding nothing.
     */
    public function get(string $service): ?Credential;

    /**
     * Remove the credential for one service, if there is one.
     *
     * @param  string  $service  The service's base URL.
     */
    public function forget(string $service): void;
}
