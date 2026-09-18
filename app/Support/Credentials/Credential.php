<?php

declare(strict_types=1);

namespace App\Support\Credentials;

use SensitiveParameter;

/**
 * A credential, wrapped so that it cannot be rendered by accident.
 *
 * **This exists because a bare `string` leaks through stack traces.** PHP captures function
 * arguments into exception traces whenever `zend.exception_ignore_args` is `0`, which is its
 * built-in default and what this machine runs. Laravel Zero renders an uncaught exception through
 * Collision, whose `ArgumentFormatter` prints string arguments up to 1000 characters -- so any
 * unhandled failure inside a store printed the token to **stdout**, which is the one place this
 * whole command line exists to keep it out of.
 *
 * Measured on 2026-09-18, before this class existed: an unwritable config directory made `mkdir()`
 * raise, Laravel's `HandleExceptions` turned that warning into an `ErrorException`, it escaped a
 * `catch (CredentialStoreFailed)`, and Collision rendered
 * `UserFileStore::put("https://…", "rcouncil_1|…")`. The control was the same probe under
 * `zend.exception_ignore_args=1`, which rendered the frame with no arguments at all.
 *
 * `ArgumentFormatter` prints an object as `Object(ClassName)`. So carrying the token in an object
 * removes it from **every** frame on **every** failure path, including the ones nobody has thought
 * of yet -- which a `catch` around today's known failures does not.
 *
 * The same reasoning drives the rest of this class: no `__toString()`, so it cannot be interpolated
 * into a message by accident; and `__debugInfo()` redacts, so `var_dump()`, `dd()` and Symfony's
 * dumper show nothing either.
 */
final class Credential
{
    /**
     * @param  string  $value  The bearer token. Marked sensitive so PHP itself redacts it from any
     *                         trace that does capture this frame.
     */
    public function __construct(
        #[SensitiveParameter]
        private readonly string $value,
    ) {}

    /**
     * The token, for the one caller that has to send it somewhere.
     *
     * Named to be conspicuous at the call site: a reader scanning for where the credential escapes
     * should find every place by searching for this method.
     */
    public function reveal(): string
    {
        return $this->value;
    }

    /**
     * Whether this is the same credential, without either side handling the string.
     *
     * Used by the stores to verify a write landed. `hash_equals` rather than `===` because the
     * comparison is on a secret, and a store's read-back should not be a timing oracle even though
     * both values are already local.
     */
    public function equals(self $other): bool
    {
        return hash_equals($this->value, $other->value);
    }

    /**
     * What a dumper sees.
     *
     * @return array<string, string> A redaction, never the value.
     */
    public function __debugInfo(): array
    {
        return ['value' => '[redacted]'];
    }
}
