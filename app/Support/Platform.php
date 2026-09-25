<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The operating system family and architecture this process runs on, as a session reports them.
 *
 * **Read, never asked** (#266). A coordinator placing platform-bound work had no way to read a
 * session's platform from the fleet, and the bridge already branches on `PHP_OS_FAMILY` to choose a
 * credential store. It is read on every session start, so a credential moved to another machine
 * reports the machine it is on now.
 *
 * **Both values describe the PHP process, not the hardware under it.** An x64 PHP running under
 * emulation on an ARM machine reports `amd64`. That is the platform the bridge's own code runs as,
 * which is what matters to work that shells out from it.
 *
 * **The bounds mirror `robot-council/core`'s `Support\Platform`** (core#351), which refuses anything
 * outside them with a 422. A value that would be refused is left out rather than sent.
 */
final class Platform
{
    /**
     * The OS families core accepts: PHP's own `PHP_OS_FAMILY` values, exactly.
     *
     * @var list<string>
     */
    public const array OS_FAMILIES = ['Windows', 'Darwin', 'Linux', 'BSD', 'Solaris', 'Unknown'];

    /**
     * What an architecture may be, lower-cased: up to 32 characters, starting with a letter or digit.
     */
    public const string ARCH = '/^[a-z0-9][a-z0-9._-]{0,31}$/D';

    /**
     * The `platform` a session start carries.
     *
     * @param  string|null  $family  The OS family, when not this process's own; for tests.
     * @param  string|null  $machine  The machine name, when not this process's own; for tests.
     * @return array{os_family: string, arch?: string}
     */
    public static function report(?string $family = null, ?string $machine = null): array
    {
        $osFamily = self::osFamily($family ?? PHP_OS_FAMILY);
        $arch = self::arch($machine ?? php_uname('m'));

        // Left out rather than sent as null: core refuses an unknown or empty value, and an
        // architecture it would refuse says nothing a missing one does not.
        return $arch === null ? ['os_family' => $osFamily] : ['os_family' => $osFamily, 'arch' => $arch];
    }

    /**
     * An OS family core accepts, from what PHP reports.
     *
     * @param  string  $family  `PHP_OS_FAMILY`, or a value standing in for it.
     */
    public static function osFamily(string $family): string
    {
        return \in_array($family, self::OS_FAMILIES, true) ? $family : 'Unknown';
    }

    /**
     * A short lower-case architecture token, or null when the machine's name for it is not one.
     *
     * Lower-cased and otherwise left as the machine names it -- `arm64`, `x86_64`, `amd64`,
     * `aarch64` -- rather than mapped to a vocabulary this repository would then have to maintain.
     *
     * @param  string  $machine  What `php_uname('m')` reported.
     */
    public static function arch(string $machine): ?string
    {
        $arch = strtolower(trim($machine));

        return preg_match(self::ARCH, $arch) === 1 ? $arch : null;
    }
}
