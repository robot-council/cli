<?php

declare(strict_types=1);

namespace App\Support\Credentials;

use FFI;
use FFI\CData;
use Throwable;

/**
 * The four `advapi32.dll` credential functions, with signatures a reader and a checker can both see.
 *
 * **This exists because `FFI` cannot be type-checked and this can.** PHP's FFI extension dispatches
 * a call to a C function through a C-level `get_method` handler rather than a declared method --
 * at runtime `(new ReflectionClass('FFI'))->hasMethod('__call')` is `false` -- so
 * `$ffi->CredReadW($a, $b)` is, to any static analyzer, a call to a method that does not exist. Left
 * in the store, six such calls would each need suppressing, and neither their argument count nor
 * their types would ever be checked.
 *
 * Declaring them here as `@method` moves that from unchecked to checked: every call site is
 * verified for arity and argument type against the signatures below, and the single unverifiable
 * line is `__call()` itself, which is genuinely dynamic dispatch and has nothing to verify. That is
 * a much smaller unchecked surface than the alternative, and it is the one place a reader should be
 * looking anyway.
 *
 * The signatures mirror `wincred.h` as `WindowsFfiCredentialStore::HEADER` declares it. A `BOOL` is
 * a 4-byte `int` there rather than a C99 `bool`, which is why these return `int`.
 *
 * @method int CredWriteW(CData $credential, int $flags)
 * @method int CredReadW(CData $targetName, int $type, int $flags, CData $credential)
 * @method int CredDeleteW(CData $targetName, int $type, int $flags)
 * @method void CredFree(CData $buffer)
 */
final class Advapi32
{
    /**
     * @param  FFI  $ffi  A binding whose header declared the `Cred*` functions above.
     */
    public function __construct(private readonly FFI $ffi) {}

    /**
     * Bind the library.
     *
     * **Loaded by bare name on purpose.** `advapi32.dll` is listed in
     * `HKLM:\SYSTEM\CurrentControlSet\Control\Session Manager\KnownDLLs`, so Windows resolves it
     * from the `\KnownDlls` section object and never consults the application directory, the working
     * directory, or `PATH`. The planting vector that made `WindowsCredentialStore` name
     * `powershell.exe` by absolute path therefore does not exist here -- and unlike an absolute
     * path, the bare name still works on a Windows installed somewhere other than `C:\Windows`.
     *
     * @param  string  $header  The C declarations to bind.
     *
     * @throws CredentialStoreFailed When FFI is disabled here, or the library will not bind.
     */
    public static function bind(string $header): self
    {
        return new self(self::translate(static fn (): FFI => FFI::cdef($header, 'advapi32.dll')));
    }

    /**
     * Allocate a C value of the given type.
     *
     * @param  string  $type  A type named in the header.
     *
     * @throws CredentialStoreFailed When FFI refuses, for the reason `translate()` gives.
     */
    public function new(string $type): CData
    {
        return self::translate(fn (): CData => $this->ffi->new($type));
    }

    /**
     * Reinterpret a value as another C type, which is how an array decays to a pointer.
     *
     * @param  string  $type  A type named in the header.
     *
     * @throws CredentialStoreFailed When FFI refuses, for the reason `translate()` gives.
     */
    public function cast(string $type, CData $value): CData
    {
        return self::translate(fn (): CData => $this->ffi->cast($type, $value));
    }

    /**
     * Call one of the C functions named in this class's `@method` list.
     *
     * The one line here that no analyzer can check, because the method name is the C symbol and is
     * only known at run time. Every caller reaches it through a declared signature above.
     *
     * @param  array<int, mixed>  $arguments
     *
     * @throws CredentialStoreFailed When FFI refuses, for the reason `translate()` gives.
     */
    public function __call(string $name, array $arguments): mixed
    {
        return self::translate(fn (): mixed => $this->ffi->$name(...$arguments));
    }

    /**
     * Turn anything the FFI extension raises into the exception a caller of a store can handle.
     *
     * **`FFI\Exception` extends `Error`, not `RuntimeException`, and that is the whole reason this
     * exists.** `ApiCommand` and `McpCommand` wrap credential resolution in
     * `catch (RuntimeException)`, so an FFI failure would travel straight past both and reach
     * Collision -- which renders a stack trace, and a trace is exactly where this codebase has
     * leaked a credential before. `robot-council/cli#35` paid for that lesson once, with
     * `InvalidArgumentException` from `Process::start()` escaping the same two handlers.
     *
     * The original is kept as `$previous`, so nothing about the cause is lost.
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
                'Windows Credential Manager could not be reached through `advapi32.dll`.',
                $throwable->getCode(),
                $throwable,
            );
        }
    }
}
