<?php

declare(strict_types=1);

namespace App\Support\Credentials;

use FFI;
use FFI\CData;
use SensitiveParameter;
use Throwable;

/**
 * Windows Credential Manager, called in-process through PHP's FFI extension.
 *
 * The same Credential Manager `WindowsCredentialStore` reaches, by the same three `advapi32.dll`
 * calls, with no subprocess in between. It is tried first and falls back to the PowerShell store
 * when FFI cannot be used, which is most machines -- `extension=ffi` is commented out in a stock
 * `php.ini`.
 *
 * **Why this is worth a second implementation rather than a micro-optimization.** Every cost below
 * is a property of starting a process, so none of them survives removing one. Measured on Windows
 * 11 Pro 26200 on 2026-09-22, both paths against the same Credential Manager:
 *
 * | | PowerShell path | this path |
 * | --- | --- | --- |
 * | `get()`, warm | about 498ms | 0.100ms |
 * | `get()`, first call on a new instance | about 498ms | 0.331ms |
 * | `put()`, which writes and reads back | about 1,150ms | about 66ms |
 * | processes created per `get()` | 1 | 0 |
 * | files left in `sys_get_temp_dir()` by a `put()` | 4 | 0 |
 * | the `Add-Type` compile, the environment block, the antivirus surface | all present | none |
 *
 * **Every timing above is one run of one script driving both shipped classes against the same
 * Credential Manager**, rather than figures gathered from separate harnesses. An earlier draft of
 * this table mixed the two, quoting a scratch helper's numbers for this path against the store's
 * own for the other -- which is not a comparison, because the two instruments did different amounts
 * of work. The first-call row is listed separately because this path parses the C header and binds
 * two libraries on its first call and the PowerShell path has no equivalent warm-up.
 *
 * The process counts come from a separate measurement, over one 8-second window per path so that
 * neither was observed for longer than the other: 13 calls through PowerShell producing 13 distinct
 * `powershell.exe` process IDs, against 93,850 calls through FFI producing none. The 13 of 13 is
 * what makes the zero an absence rather than a blind sweep.
 *
 * That ratio is why `robot-council/cli#34` -- reading several credentials in one call, to make a
 * refusal that lists what is enrolled bearable -- does not need to exist on a machine that takes
 * this path.
 *
 * **Loading `advapi32.dll` and `kernel32.dll` by bare name is deliberate, and safer here than an
 * absolute path.** Both are listed in
 * `HKLM:\SYSTEM\CurrentControlSet\Control\Session Manager\KnownDLLs` (verified on this machine),
 * and Windows resolves a KnownDLL from the `\KnownDlls` section object without consulting the
 * application directory, the working directory, or `PATH`. So the planting vector that made
 * `WindowsCredentialStore` name `powershell.exe` by absolute path does not exist for these, and
 * unlike an absolute path the bare name still works on a Windows installed somewhere other than
 * `C:\Windows` -- a machine that store documents as unsupported.
 *
 * **What this does not claim.** The credential is protected by DPAPI under this user's profile, so
 * anything already running as this user can read it back, exactly as `CredentialStore` says of
 * every store here. Removing the subprocess narrows where the plaintext appears -- no argv, no
 * stdin pipe, no spooled temporary file -- and does not put the credential beyond a same-user
 * process.
 */
final class WindowsFfiCredentialStore implements CredentialStore
{
    /**
     * `ERROR_NOT_FOUND`, which `CredReadW` and `CredDeleteW` set when nothing is filed at a target.
     *
     * The one Windows error that is an answer rather than a fault, and the distinction
     * `robot-council/cli#39` turns on. Every other code raises.
     */
    public const int NOT_FOUND = 1168;

    /**
     * `CRED_TYPE_GENERIC`, matching what the PowerShell path writes.
     *
     * Load-bearing for agreement between the two stores: `CredReadW` keys on the type as well as
     * the target, so a credential written as one type is invisible to a read of another.
     */
    private const int GENERIC = 1;

    /**
     * `CRED_PERSIST_LOCAL_MACHINE`, matching what the PowerShell path writes.
     */
    private const int PERSIST_LOCAL_MACHINE = 2;

    /**
     * The `advapi32` surface, declared for PHP's C parser rather than copied from `wincred.h`.
     *
     * Three things here are deliberate rather than stylistic:
     *
     * - **`unsigned short *` stands in for `LPWSTR`.** PHP's FFI parser does not know `wchar_t`, and
     *   a Windows `wchar_t` is 16 bits, so the strings are built as UTF-16LE by hand in `wide()`.
     * - **`int` stands in for `BOOL`, not `bool`, and the consequence was measured rather than
     *   reasoned about.** A Win32 `BOOL` is a 4-byte `int`. Declared as C99 `bool` on 2026-09-22,
     *   `CredReadW` returned PHP `false` instead of `0` for a target nothing was filed at -- and
     *   the earlier `$found = ... !== 0` test then read `false !== 0` as **true**, treating a
     *   not-found credential as found and going on to dereference an out-pointer `advapi32` had
     *   never set. That is a segmentation fault: exit 139, nothing on either stream, no exception.
     *   `read()` no longer depends on this declaration for its decision, and checks the pointer
     *   before dereferencing it, so the same edit now produces a message. The declaration is still
     *   `int`, because being right about what the ABI returns is not something to leave to a guard.
     * - **The types carry a `ROBOT_` prefix** so a parse error names something greppable rather
     *   than colliding with a name a reader would expect to mean the Windows structure.
     *
     * The layout is checked rather than trusted: `FFI::sizeof()` on this structure returns 80 on a
     * 64-bit build, which is what `CREDENTIALW` measures there, and a test asserts it.
     */
    private const string HEADER = <<<'C_WRAP'
    typedef struct {
        unsigned long dwLowDateTime;
        unsigned long dwHighDateTime;
    } ROBOT_FILETIME;
    
    typedef struct {
        unsigned long Flags;
        unsigned long Type;
        unsigned short *TargetName;
        unsigned short *Comment;
        ROBOT_FILETIME LastWritten;
        unsigned long CredentialBlobSize;
        unsigned char *CredentialBlob;
        unsigned long Persist;
        unsigned long AttributeCount;
        void *Attributes;
        unsigned short *TargetAlias;
        unsigned short *UserName;
    } ROBOT_CREDENTIALW;
    
    int CredWriteW(ROBOT_CREDENTIALW *Credential, unsigned long Flags);
    int CredReadW(unsigned short *TargetName, unsigned long Type, unsigned long Flags, ROBOT_CREDENTIALW **Credential);
    int CredDeleteW(unsigned short *TargetName, unsigned long Type, unsigned long Flags);
    void CredFree(void *Buffer);
    C_WRAP;

    /**
     * Whether the mechanism answered, memoized for the life of this instance.
     *
     * Instance state rather than static, so a test can get a fresh answer from a new object, and
     * one instance is resolved per command run by `CredentialsServiceProvider`.
     */
    private ?bool $available = null;

    private ?Advapi32 $advapi = null;

    private ?Kernel32 $kernel = null;

    public function available(): bool
    {
        return $this->available ??= $this->probe();
    }

    public function describe(): string
    {
        // **Deliberately identical to `WindowsCredentialStore::describe()`.** This is printed to a
        // developer after enrollment so they can go and look at what was stored, and the answer to
        // "where is it" is the same for both stores. Naming the mechanism here would make the line
        // change with a machine's `php.ini` while the credential had not moved at all.
        return sprintf('Windows Credential Manager, under a `%s…` target (`cmdkey /list` lists it)', WindowsCredentialTarget::PREFIX);
    }

    public function put(string $service, Credential $credential): void
    {
        $token = $credential->reveal();

        if (\strlen($token) > WindowsCredentialTarget::MAX_BLOB_BYTES) {
            throw new CredentialStoreFailed(sprintf(
                'The credential is longer than the %d bytes Windows Credential Manager accepts.',
                WindowsCredentialTarget::MAX_BLOB_BYTES,
            ));
        }

        [$wrote, $error] = $this->write($this->target($service), $service, $token);

        if (! $wrote) {
            // The PowerShell path cannot say this. Its helper throws a .NET exception that arrives
            // as exit 1 with the code buried in a message, so an over-long user name and a refused
            // `Add-Type` are the same answer there. Here the code is in hand, so it is named.
            throw new CredentialStoreFailed(sprintf(
                'Windows Credential Manager refused the credential (CredWriteW failed with Windows error %d).',
                $error,
            ));
        }

        // Not the return value. The other stores read back because a store that reports success and
        // holds nothing is the failure that costs the most later, and that reasoning does not depend
        // on which mechanism is underneath.
        $stored = $this->get($service);

        if (! $stored instanceof Credential || ! $stored->equals($credential)) {
            throw new CredentialStoreFailed('The credential could not be stored in Windows Credential Manager.');
        }
    }

    /**
     * The credential for one service, or null when none is stored.
     *
     * **Null means "not enrolled", and nothing else**, per `CredentialStore::get()` and the decision
     * on `robot-council/cli#39`. This store draws that line more sharply than the PowerShell one
     * can: `CredReadW` reports `ERROR_NOT_FOUND` distinctly from every other failure, so there is no
     * exit code standing in for a family of faults.
     *
     * @param  string  $service  The credential key.
     *
     * @throws CredentialStoreFailed When the mechanism failed, as opposed to holding nothing.
     */
    public function get(string $service): ?Credential
    {
        [$found, $error, $blob] = $this->read($this->target($service));

        if (! $found) {
            // A machine that is simply not enrolled. The only answer that is not a fault.
            if ($error === self::NOT_FOUND) {
                return null;
            }

            throw new CredentialStoreFailed(sprintf(
                'Windows Credential Manager could not be read (CredReadW failed with Windows error %d).',
                $error,
            ));
        }

        // **The same accepted gap `WindowsCredentialStore::get()` documents, reached the same way.**
        // An entry whose blob is zero bytes is a successful read of something that is not a
        // credential, so there is no error code to name and it reads as "not enrolled". Measured
        // here on 2026-09-22: writing a zero-length blob succeeds and reads back as an empty string.
        // Stated rather than claimed impossible.
        if ($blob === '') {
            return null;
        }

        return new Credential($blob);
    }

    /**
     * Remove the credential for one service, if there is one.
     *
     * **A `CredDeleteW` that fails is deliberately not raised, and that is parity rather than an
     * oversight.** `forget()` returns void in every implementation and none of them inspects what it
     * ran, which `Credentials::adopt()` depends on: it calls `forget()` and then *reads the entry
     * back*, because a delete that did not happen would otherwise be invisible. Raising here would
     * make that call site throw a message about `CredDeleteW` in place of the one it composes about
     * a claimed credential and a leftover -- the two facts an operator actually acts on. Whether the
     * interface should let `forget()` report failure is a question for every store at once, not one
     * this class settles on its own.
     *
     * **A broken FFI binding is a different thing and does raise**, out of `Advapi32`, because that
     * is not a delete reporting a result -- it is the mechanism being unreachable, and the
     * alternative is an `Error` escaping every handler. It arrives as `CredentialStoreFailed`, which
     * `adopt()`'s caller already handles.
     *
     * @param  string  $service  The credential key.
     *
     * @throws CredentialStoreFailed When `advapi32` cannot be reached at all.
     */
    public function forget(string $service): void
    {
        $advapi = $this->advapi();
        $target = $this->wide($this->target($service));

        $this->kernel()->clearLastError();

        // `ERROR_NOT_FOUND` here means the credential is already gone, which is the state being
        // asked for rather than a failure -- so nothing distinguishes it from success, and the
        // return value is discarded for the reason above.
        $advapi->CredDeleteW($advapi->cast('unsigned short*', $target), self::GENERIC, 0);
    }

    /**
     * The Credential Manager target a key is filed under.
     *
     * Delegates to `WindowsCredentialTarget` rather than deriving anything, so this store and
     * `WindowsCredentialStore` cannot disagree about where a credential lives. Public so a test can
     * assert the two return identical targets for the same key.
     *
     * @param  string  $service  The credential key, which nothing here parses.
     * @return string The target name.
     */
    public function target(string $service): string
    {
        return WindowsCredentialTarget::for($service);
    }

    /**
     * Whether `FFI::sizeof()` agrees with `CREDENTIALW` as this build lays it out.
     *
     * Public so a test can read it without reaching through reflection. 80 bytes on a 64-bit build
     * and 52 on a 32-bit one: the structure is eight pointers and five 32-bit fields with natural
     * alignment, so a mismatch means the declaration in `HEADER` has drifted from the one
     * `advapi32` reads -- which would corrupt memory rather than fail cleanly.
     *
     * @return int The size in bytes, or zero when FFI cannot be used here at all.
     */
    public function structureSize(): int
    {
        try {
            return FFI::sizeof($this->advapi()->new('ROBOT_CREDENTIALW'));
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * Run the mechanism once, and report whether it reached a verdict.
     *
     * **Probed rather than inferred from `extension_loaded('FFI')`**, which is necessary and nowhere
     * near sufficient. `ffi.enable` defaults to `preload`, under which `FFI::cdef()` is refused
     * outside a preloaded script and outside the CLI SAPI -- this application is always CLI, which
     * is why the path is usable here at all, and a test asserts that rather than leaving it to
     * luck. The extension can also be loaded on a machine where `advapi32` will not bind.
     *
     * The standard of evidence is the one `WindowsCredentialStore::probe()` sets: not "did not
     * fail", but `ERROR_NOT_FOUND` exactly, from a target nothing is filed at. A mechanism that
     * never reached `CredReadW` cannot produce that code.
     *
     * **Nothing in a probe may throw.** `random_bytes()` can raise, `FFI::cdef()` raises when the
     * extension is disabled, and a missing class raises an `Error` rather than an exception.
     * `Credentials::store()` does not guard this call, and neither `ApiCommand` nor `McpCommand`
     * catches anything but `RuntimeException`, so an escaping throwable would reach Collision
     * instead of falling through to the next store.
     *
     * @return bool Whether Credential Manager can be reached through FFI from here.
     */
    private function probe(): bool
    {
        if (PHP_OS_FAMILY !== 'Windows' || ! extension_loaded('FFI')) {
            return false;
        }

        try {
            [$found, $error] = $this->read(WindowsCredentialTarget::probe());
        } catch (Throwable) {
            return false;
        }

        return ! $found && $error === self::NOT_FOUND;
    }

    /**
     * Read one target, without deciding what its answer means.
     *
     * @param  string  $target  The Credential Manager target to read.
     * @return array{0: bool, 1: int, 2: string} Whether it was found, the Windows error code when
     *                                           it was not, and the blob when it was.
     */
    private function read(string $target): array
    {
        $advapi = $this->advapi();
        $kernel = $this->kernel();

        // Named locals, not inline expressions. A pointer into a buffer neither owns nor keeps
        // alive what it points at, so building these inside the call would let PHP free the buffer
        // at the end of the statement and leave `advapi32` reading freed memory.
        $targetBuffer = $this->wide($target);
        $out = $advapi->new('ROBOT_CREDENTIALW*');

        $kernel->clearLastError();

        // **A cast, not `!== 0`, and that is measured rather than stylistic.** The comparison is
        // only correct while `HEADER` declares this function's return as `int`: measured on
        // 2026-09-22, declaring it as C99 `bool` makes a failed read return `false`, and
        // `false !== 0` is **true** under strict comparison -- so a not-found credential would be
        // treated as found. A `(bool)` cast gives `false` for both `0` and `false`, so the one-word
        // header edit that silently inverted this decision cannot.
        $found = (bool) $advapi->CredReadW($advapi->cast('unsigned short*', $targetBuffer), self::GENERIC, 0, FFI::addr($out));
        $error = $kernel->lastError();

        if (! $found) {
            return [false, $error, ''];
        }

        // **The out-pointer is checked for null before it is dereferenced, and the reason is that
        // the alternative is a segmentation fault.** `advapi32` sets this pointer only on success,
        // so nothing should reach here with it null -- but measured on 2026-09-22, dereferencing a
        // null `ROBOT_CREDENTIALW*` kills the process with exit 139, no output on either stream and
        // no exception to catch. That is the worst failure this class could have: a credential
        // command that vanishes without a diagnostic. `FFI::isNull()` costs one call and turns it
        // into a message.
        if (FFI::isNull($out)) {
            throw new CredentialStoreFailed('Windows Credential Manager reported a credential it did not supply.');
        }

        try {
            // **Dereferencing the out-pointer, then narrowing what comes back.** Both checks below
            // guard against a shape `advapi32` has never produced, and neither is a formality: a
            // structure whose declaration had drifted would fail here rather than by reading a
            // credential out of whatever the wrong offset happens to hold. Raising beats casting,
            // because a cast would turn a garbage pointer into a confident answer.
            $structure = $out[0];

            if (! $structure instanceof CData) {
                throw new CredentialStoreFailed('Windows Credential Manager returned something other than a credential structure.');
            }

            $size = $structure->CredentialBlobSize;

            if (! \is_int($size)) {
                throw new CredentialStoreFailed('Windows Credential Manager reported a credential size that is not a number.');
            }

            // A zero-length blob has no buffer worth reading, and `CredentialBlob` may be null.
            if ($size <= 0) {
                return [true, 0, ''];
            }

            $blob = $structure->CredentialBlob;

            if (! $blob instanceof CData) {
                throw new CredentialStoreFailed('Windows Credential Manager reported a credential of a size it did not supply.');
            }

            // `FFI::string()` copies into a PHP string, which is plaintext in this process's memory
            // and movable by the allocator. That is the same exposure every store has -- the
            // credential has to become a string to be used -- and it is narrower than the
            // PowerShell path's, where the value also crossed a pipe.
            return [true, 0, FFI::string($blob, $size)];
        } finally {
            // `advapi32` allocated this buffer; failing to hand it back leaks the credential's
            // plaintext into a heap block nothing will clear for the life of the process. In a
            // `finally` so the narrowing failures above cannot skip it.
            $advapi->CredFree($advapi->cast('void*', $out));
        }
    }

    /**
     * Write one target.
     *
     * @param  string  $target  The Credential Manager target to write.
     * @param  string  $userName  What to record in the credential's user name field, which the
     *                            Credential Manager UI shows and nothing here reads back.
     *                            **Windows refuses one over 512 characters** with error 1734,
     *                            measured on 2026-09-22 -- a character fewer than the documented
     *                            `CRED_MAX_USERNAME_LENGTH` of 513, which counts the terminator.
     *                            The PowerShell path passes the same value and fails at the same
     *                            length, so this is a shared ceiling on the service key rather than
     *                            anything FFI introduces. Neither store checks it up front, and the
     *                            message a developer gets does not name it; `robot-council/cli#63`
     *                            is where that is fixed for both.
     * @param  string  $blobBytes  The credential's bytes. **Marked sensitive, and that attribute is
     *                             load-bearing.** PHP captures function arguments into every
     *                             exception trace while `zend.exception_ignore_args` is `0`, which
     *                             is its default, and Collision prints string arguments up to 1000
     *                             characters. `Credential` keeps the token out of `put()`'s frame by
     *                             being an object; this parameter would have put it straight back
     *                             into the next frame down. **The test asserts the attribute is on
     *                             this parameter, through reflection -- it does not render a trace
     *                             and read it back**, which is the stronger check and the one the
     *                             PowerShell store's equivalent test does not make either. What
     *                             renders as `Object(SensitiveParameterValue)` rather than the token
     *                             was measured by hand on 2026-09-21, for that store.
     * @return array{0: bool, 1: int} Whether it was written, and the Windows error code when not.
     */
    private function write(
        string $target,
        string $userName,
        #[SensitiveParameter]
        string $blobBytes,
    ): array {
        $advapi = $this->advapi();
        $length = \strlen($blobBytes);

        // Named locals, for the lifetime reason given in `read()`.
        $targetBuffer = $this->wide($target);
        $userBuffer = $this->wide($userName);
        $blob = $advapi->new('unsigned char['.max(1, $length).']');

        if ($length > 0) {
            FFI::memcpy($blob, $blobBytes, $length);
        }

        // `cast()` rather than the address of element zero, and the difference is not cosmetic:
        // `$buffer[0]` on a scalar array evaluates to a PHP `int`, and `FFI::addr()` of it happens
        // to yield a usable pointer through a reference path that is easy to read as a mistake.
        // Casting the array to a pointer is C's own array decay, says what is meant, and was
        // measured round-tripping a credential with a control on the absent case.
        $credential = $advapi->new('ROBOT_CREDENTIALW');
        $credential->Type = self::GENERIC;
        $credential->TargetName = $advapi->cast('unsigned short*', $targetBuffer);
        $credential->CredentialBlobSize = $length;
        $credential->CredentialBlob = $advapi->cast('unsigned char*', $blob);
        $credential->Persist = self::PERSIST_LOCAL_MACHINE;
        $credential->UserName = $advapi->cast('unsigned short*', $userBuffer);

        try {
            $kernel = $this->kernel();
            $kernel->clearLastError();

            // `(bool)` rather than `!== 0`, for the reason `read()` records: the comparison depends
            // on the header declaring an `int` return, and the cast does not.
            return [(bool) $advapi->CredWriteW(FFI::addr($credential), 0), $kernel->lastError()];
        } finally {
            // Zero the buffer rather than releasing it to an allocator the next allocation can read.
            // **This is not every plaintext copy**: the PHP string this was copied from is managed,
            // uncleared, and movable, exactly as the PowerShell helper's C# comment says of its own
            // managed copies. Clearing what can be cleared is worth doing; believing it leaves no
            // plaintext behind is not.
            if ($length > 0) {
                FFI::memset($blob, 0, $length);
            }
        }
    }

    /**
     * A NUL-terminated UTF-16LE buffer, which is what `LPWSTR` means on Windows.
     *
     * The buffer is one code unit longer than the string and PHP zero-fills what it allocates, so
     * the terminator is the tail rather than something written separately.
     *
     * Non-ASCII survives this: measured on 2026-09-22 with a target holding `e-acute`, two kanji and
     * an emoji -- 62 UTF-8 bytes becoming 55 UTF-16 code units, the emoji as a surrogate pair --
     * written and read back byte-identical, with a target differing only in one kanji correctly
     * reported as absent.
     *
     * @param  string  $value  A UTF-8 string.
     * @return CData The buffer, which the caller must keep alive for as long as the pointer is used.
     */
    private function wide(string $value): CData
    {
        $bytes = mb_convert_encoding($value, 'UTF-16LE', 'UTF-8');
        $units = \intdiv(\strlen($bytes), 2) + 1;

        $buffer = $this->advapi()->new("unsigned short[$units]");

        if ($bytes !== '') {
            FFI::memcpy($buffer, $bytes, \strlen($bytes));
        }

        return $buffer;
    }

    /**
     * The `advapi32` binding, built once per instance.
     *
     * @throws CredentialStoreFailed When FFI is disabled here or the library will not bind.
     *                               `Advapi32::translate()` records why that conversion matters.
     */
    private function advapi(): Advapi32
    {
        return $this->advapi ??= Advapi32::bind(self::HEADER);
    }

    /**
     * The `kernel32` binding, built once per instance.
     *
     * @throws CredentialStoreFailed When FFI is disabled here or the library will not bind.
     *                               `Advapi32::translate()` records why that conversion matters.
     */
    private function kernel(): Kernel32
    {
        return $this->kernel ??= Kernel32::bind();
    }
}
