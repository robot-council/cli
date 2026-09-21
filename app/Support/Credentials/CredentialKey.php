<?php

declare(strict_types=1);

namespace App\Support\Credentials;

/**
 * The string a credential is filed under, for one fleet and one harness.
 *
 * **One machine holds one credential per harness per fleet**, which is the shape
 * `robot-council/cli#21` decided: it mirrors what the service treats as one installation, so
 * re-enrolling a harness replaces that harness's credential rather than adding another nobody can
 * tell apart. Keying by the installation id the service returns was rejected for the opposite
 * reason -- `Installations::createFrom()` always creates and never upserts, so an id-keyed store
 * would gain a slot on every re-enrollment and never replace one.
 *
 * **Every store already addresses an entry by one opaque string**, so this changes none of them:
 * the Keychain puts the key in `-a` with `-s` fixed to `robot-council`, the Secret Service uses a
 * single attribute, and the file store uses a JSON key. That is also why the Windows store
 * (`robot-council/cli#7`) needs nothing from this.
 */
final class CredentialKey
{
    /**
     * What separates the fleet from the harness.
     *
     * **A vertical bar cannot occur in either half**, which is what makes the key unambiguous
     * rather than merely unlikely to collide. A harness is `[a-z0-9-]` (`MachineIdentity::HARNESS`),
     * and a bar is not legal unencoded in a URL -- RFC 3986 excludes it from every component, and
     * every client this speaks to percent-encodes it as `%7C`. So no service and no harness can
     * smuggle one in and be read back as the other.
     */
    public const string SEPARATOR = '|';

    /**
     * The key one harness's credential for one fleet is stored under.
     *
     * @param  string  $service  The fleet's base URL.
     * @param  string  $harness  The harness this credential belongs to.
     * @return string The key.
     */
    public static function for(string $service, string $harness): string
    {
        return $service.self::SEPARATOR.$harness;
    }

    /**
     * The key a machine enrolled before `robot-council/cli#23` used.
     *
     * The bare fleet URL, with no harness in it. Kept readable so that no machine has to re-enroll
     * on upgrade; what it is *not* is adopted automatically, which `robot-council/cli#24` owns.
     *
     * @param  string  $service  The fleet's base URL.
     * @return string The key.
     */
    public static function legacy(string $service): string
    {
        return $service;
    }
}
