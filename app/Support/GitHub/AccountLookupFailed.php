<?php

declare(strict_types=1);

namespace App\Support\GitHub;

use RuntimeException;

/**
 * Why one GitHub login could not be turned into a numeric account ID.
 *
 * **The two cases are not the same, and the command behaves differently for each.** A login that
 * does not exist is the person's typo, and the fix is to ask again. A lookup that could not be made
 * -- rate limited, offline -- says nothing about the login, and asking again just fails again.
 *
 * Collapsing them would produce the worst outcome available: re-prompting somebody for a login
 * that was correct all along, once per attempt, until they give up or invent a different one.
 */
final class AccountLookupFailed extends RuntimeException
{
    /**
     * @param  string  $message  What to tell the person.
     * @param  bool  $unknown  True when GitHub answered and had no such account.
     */
    private function __construct(string $message, public readonly bool $unknown)
    {
        parent::__construct($message);
    }

    /**
     * GitHub answered, and there is no such account.
     *
     * @param  string  $login  The login that was asked about.
     */
    public static function unknown(string $login): self
    {
        return new self(sprintf('GitHub has no account called `%s`.', $login), true);
    }

    /**
     * GitHub could not be asked, so nothing is known about the login either way.
     *
     * @param  string  $why  What went wrong.
     */
    public static function unavailable(string $why): self
    {
        return new self($why, false);
    }
}
