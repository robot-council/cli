<?php

declare(strict_types=1);

namespace App\Support\Credentials;

use FFI;
use Throwable;

/**
 * The two `kernel32.dll` calls that make a Windows error code readable.
 *
 * Separate from `Advapi32` because they are a separate library: `FFI::cdef()` binds one DLL, and
 * symbol lookup happens inside it, so `GetLastError` cannot be declared alongside the `Cred*`
 * functions. It carries its own `@method` list, and converts what FFI raises, for the reasons
 * `Advapi32` records.
 *
 * **`GetLastError()` reached through this second handle does report what an `advapi32` call set**,
 * which is not obvious and is the measurement the whole FFI path depended on: the two libraries are
 * opened separately, and nothing promises PHP will not make its own Win32 calls in between and
 * clear the value. Measured on 2026-09-22 -- a `CredReadW` against a target nothing is filed at
 * returns false and `GetLastError()` returns 1168 -- and again across 93,850 consecutive reads
 * without a wrong code.
 *
 * `kernel32.dll` is a KnownDLL, so it is loaded by bare name for the reason `Advapi32` records.
 *
 * @method int GetLastError()
 * @method void SetLastError(int $code)
 */
final class Kernel32
{
    public function __construct(private readonly FFI $ffi) {}

    /**
     * Bind the library.
     *
     * @throws CredentialStoreFailed When FFI is disabled here, or the library will not bind.
     */
    public static function bind(): self
    {
        return new self(self::translate(static fn (): FFI => FFI::cdef(
            'unsigned long GetLastError(void); void SetLastError(unsigned long code);',
            'kernel32.dll',
        )));
    }

    /**
     * The calling thread's last Windows error code.
     *
     * Declared rather than left to `__call` so the `int` is real: a caller uses this to decide
     * whether a failure means "no such credential" or something that must be raised, and a `mixed`
     * there would only move the narrowing somewhere less obvious.
     *
     * @throws CredentialStoreFailed When FFI refuses.
     */
    public function lastError(): int
    {
        return $this->GetLastError();
    }

    /**
     * Clear the last error before a call, so the code read after it is known to belong to it.
     *
     * Without this a failure that sets no error code is reported with whatever stale value was
     * already there, which reads as a completely unrelated fault.
     *
     * @throws CredentialStoreFailed When FFI refuses.
     */
    public function clearLastError(): void
    {
        $this->SetLastError(0);
    }

    /**
     * @param  array<int, mixed>  $arguments
     *
     * @throws CredentialStoreFailed When FFI refuses.
     */
    public function __call(string $name, array $arguments): mixed
    {
        return self::translate(fn (): mixed => $this->ffi->$name(...$arguments));
    }

    /**
     * Turn anything the FFI extension raises into the exception a caller of a store can handle.
     *
     * The reasoning is `Advapi32::translate()`'s, and the message names this library so a failure
     * says which one it was.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $call  The FFI work to run.
     * @return TReturn Whatever it returned.
     *
     * @throws CredentialStoreFailed When it raised instead.
     */
    private static function translate(callable $call): mixed
    {
        try {
            return $call();
        } catch (Throwable $throwable) {
            throw new CredentialStoreFailed(
                'Windows Credential Manager could not be reached through `kernel32.dll`.',
                $throwable->getCode(),
                $throwable,
            );
        }
    }
}
