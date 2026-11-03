<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use InvalidArgumentException;

/**
 * Which harnesses can be woken, and how to reach one (#381).
 *
 * **Empty until an adapter ships.** Each harness's adapter registers itself here with a factory that
 * takes the configured target and returns the adapter, or throws `InvalidArgumentException` saying
 * what is wrong with the target. A harness with no entry cannot be woken, and asking for it is said
 * and ignored rather than fatal.
 */
final class WakeAdapters
{
    /**
     * @param  array<string, Closure(string):WakeAdapter>  $factories  By harness name.
     */
    public function __construct(private readonly array $factories = []) {}

    /**
     * The adapter for a harness and a target.
     *
     * @param  string  $harness  Which harness this bridge serves.
     * @param  string  $target  The configured target.
     * @return WakeAdapter|string The adapter, or why there is none.
     */
    public function for(string $harness, string $target): WakeAdapter|string
    {
        if (! isset($this->factories[$harness])) {
            return \sprintf('there is no wake adapter for the "%s" harness', $harness);
        }

        try {
            return $this->factories[$harness]($target);
        } catch (InvalidArgumentException $invalidArgumentException) {
            return $invalidArgumentException->getMessage();
        }
    }
}
