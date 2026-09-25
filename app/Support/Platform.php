<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The operating system family and architecture this process runs on, as a session reports them.
 *
 * **Read, never asked** (#266). A coordinator placing platform-bound work had no way to read a
 * session's platform from the fleet, and the bridge is the one party that knows it for certain: it
 * already branches on `PHP_OS_FAMILY` to choose a credential store. It is read on every session
 * start, so a credential moved to another machine reports the machine it is on now.
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
     * @return array{os_family: string, arch?: string}
     */
    public static function report(): array
    {
        $arch = self::arch(php_uname('m'));

        return $arch === null
            ? ['os_family' => self::osFamily(PHP_OS_FAMILY)]
            : ['os_family' => self::osFamily(PHP_OS_FAMILY), 'arch' => $arch];
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
