<?php

declare(strict_types=1);

namespace App\Support\Credentials;

use Illuminate\Support\Sleep;
use SensitiveParameter;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Windows Credential Manager, through a `CredWriteW` call made from PowerShell.
 *
 * **The token is written on stdin, never as an argument**, which is the whole reason this is not
 * three lines of `cmdkey`. `cmdkey /generic:… /pass:<token>` puts the credential in the process's
 * argv, where any process running as this user can read it out of
 * `Get-CimInstance Win32_Process`. That is the exposure `KeychainStore` goes to some trouble to
 * avoid on macOS, and Windows offers no stdin equivalent for `cmdkey` -- so `cmdkey` is not used at
 * all. The script below is handed to `powershell.exe` as one inline argument, carrying no secret,
 * and the credential arrives on stdin. `run()` records why it travels that way rather than as a
 * file or an `-EncodedCommand`.
 *
 * Measured on Windows 11 Pro 26200 on 2026-09-21, with the same token in both halves and the
 * subject process held open on stdin so the instrument's speed was not a variable:
 *
 * - **Positive control** -- `cmd /c cmdkey … /pass:<token>`: `Win32_Process.CommandLine` read back
 *   `… /pass:<token> …`. So the instrument can see a same-user command line, and a token passed as
 *   an argument is in it.
 * - **This store** -- the command line read back in full, with no `<CommandLine unreadable>`, and
 *   the token was absent from it.
 *
 * Without that first half the second is worth nothing: about a fifth of the rows in a machine-wide
 * sweep come back with a null `CommandLine`, and "not found" and "could not have been found" look
 * identical.
 *
 * **Why `CredWriteW` rather than a PowerShell credential module.** `CredentialManager` is a
 * PowerShell Gallery module, so it is absent on a stock machine and installing it is not something
 * an enrollment should do. `advapi32.dll` is always there.
 *
 * **Why PowerShell rather than PHP's FFI extension.** FFI would call `advapi32` in-process, with no
 * subprocess at all, and it works where it is enabled -- verified here. But `extension=ffi` is
 * commented out in a stock `php.ini`, so the store would report itself unavailable on most Windows
 * machines and they would keep the file fallback this issue exists to replace. `powershell.exe`
 * ships with Windows. The cost of that choice is measured in `TIMEOUT_SECONDS` below.
 *
 * **What this does not claim.** The credential is protected by DPAPI under this user's profile,
 * which means anything already running as this user can read it back -- exactly as
 * `CredentialStore` says of every store here. What it buys is that the token is not in a process
 * list, not in a script, and not in a world-readable file.
 */
final class WindowsCredentialStore implements CredentialStore
{
    /**
     * The prefix every credential is filed under, so the targets this store owns are greppable in
     * `cmdkey /list` and cannot collide with anything else on the machine.
     *
     * **Held on `WindowsCredentialTarget` and referenced here**, so this store and
     * `WindowsFfiCredentialStore` cannot drift to different prefixes. The name stays because it is
     * what callers and tests already say.
     */
    public const string TARGET_PREFIX = WindowsCredentialTarget::PREFIX;

    /**
     * How long any one `powershell.exe` call may take.
     *
     * Generous because the call compiles the interop shim on every invocation. Measured through
     * this class on 2026-09-21: about 650ms for a `get()`, and about 1.3s for a `put()`, which is
     * two calls because it reads its own write back. Of one call, roughly 160ms is `powershell.exe`
     * starting and most of the rest is `Add-Type` running the .NET compiler. A machine under load
     * or with a cold compiler takes longer, and this bound only needs to sit above the worst case
     * rather than near the typical one.
     */
    public const int TIMEOUT_SECONDS = 30;

    /**
     * The exit code the script uses for "there is no such credential".
     *
     * Distinct from any failure, so a missing credential and a broken mechanism are not the same
     * answer. `available()` demands exactly this code from a target nothing is filed at, which a
     * mechanism that never reached `CredReadW` cannot produce.
     */
    private const int NOT_FOUND = 3;

    /**
     * The interpreter the credential is piped into, named literally.
     *
     * Public so a test can assert that a redirected `SystemRoot` does not move it. `powershell()`
     * explains why nothing about this path comes from the environment.
     */
    public const string INTERPRETER = 'C:\Windows\System32\WindowsPowerShell\v1.0\powershell.exe';

    /**
     * The most of the Windows environment block this store will take for itself, in UTF-16 code
     * units.
     *
     * **The resource this guards is not the one it looks like.** The script is passed as an inline
     * argument, but it never reaches the command line: `prepareWindowsCommandLine()` rewrites any
     * quoted argument containing `!LF!` or `""` into an environment variable and leaves `!uid!`
     * behind for delayed expansion. Measured on 2026-09-21 by reading `Win32_Process.CommandLine`
     * of a live call -- the `cmd.exe` line is **259 characters**, and the script is not in it.
     *
     * So the 8191-character command-line limit is nowhere near, and what the script actually
     * consumes is the environment block, which Windows caps at 32767 UTF-16 code units and which
     * Symfony checks in `validateWindowsEnvBlockSize()`. The machine's own environment shares that
     * budget: about 5,500 code units on the machine this was written on, against roughly 5,900 for
     * the hoisted script.
     *
     * This bounds what the store takes, so the script growing cannot quietly squeeze a machine
     * with a large environment over the limit. A test asserts it.
     */
    public const int ENVIRONMENT_BUDGET = 8192;

    /**
     * How much the helper may write to one stream without the parent draining it, in bytes.
     *
     * **A floor, not the pipe's capacity.** Measured on 2026-09-22 by polling a child through the
     * same `proc_open` mechanism `readThroughPipe()` uses and never reading: 4,096 bytes fit and the
     * child exits, 8,192 blocks. The true capacity is somewhere between, and this is the figure
     * safe to reason with.
     *
     * **Identical on stdout and stderr**, which was worth measuring rather than assuming --
     * `robot-council/cli#58` exists because the original bound reasoned about stdout alone.
     * `readThroughPipe()` records what each stream can actually produce against it.
     */
    public const int PIPE_BUFFER_BYTES = 4096;

    /**
     * The largest blob `CredWriteW` accepts, in bytes.
     *
     * `CRED_MAX_CREDENTIAL_BLOB_SIZE`, held on `WindowsCredentialTarget` so both Windows stores
     * refuse at the same size. Checked here so that an over-long credential fails saying so, rather
     * than through a read-back that only reports the value did not land.
     */
    private const int MAX_BLOB_BYTES = WindowsCredentialTarget::MAX_BLOB_BYTES;

    /**
     * The PowerShell that does the work, held in `advapi32.dll` rather than in any CLI.
     *
     * Read from environment variables rather than interpolated into this script: the service URL is
     * caller-controlled, and a URL carrying a quote would otherwise close the string and run
     * whatever followed it. There is no escaping to get wrong if there is no interpolation.
     *
     * The credential itself travels base64-encoded, in both directions. That is transport encoding
     * and not protection -- it is there because stdout is decoded through the console's code page,
     * which mangles arbitrary bytes, and base64 is ASCII whatever that code page is.
     *
     * **`$ErrorActionPreference = 'Stop'` on the first line is load-bearing for two separate
     * properties, and a test pins it.** Measured on 2026-09-22 by removing it and re-running:
     *
     * - **It is what bounds stderr.** With it, 1, 10 and 30 distinct C# compiler errors all produce
     *   about 732 bytes, because the first error is terminating and there is never a second to
     *   report. Without it the script runs on and emits an error per statement: 1,385 bytes at one
     *   error, **7,991 at ten**, and **22,809 at thirty** -- past `PIPE_BUFFER_BYTES` and into the
     *   range where the child blocks and `readThroughPipe()` can only time out.
     *   `robot-council/cli#58` is where that was settled.
     * - **It is also what makes a broken mechanism report a failure.** Without it, a refused
     *   `Add-Type` is non-terminating, `Read()` leaves `$blob` null, and the script reaches
     *   `exit 3` -- which is `NOT_FOUND`. So a machine where the helper cannot compile at all would
     *   report "no such credential" from every `get()`, and `available()`'s probe, which demands
     *   exactly that code, would pass. That is the defect `robot-council/cli#39` closed, arriving
     *   through a different door.
     */
    private const string SCRIPT = <<<'POWERSHELL'
        $ErrorActionPreference = 'Stop'

        Add-Type -TypeDefinition @'
        using System;
        using System.Runtime.InteropServices;

        public static class RobotCouncilCredential
        {
            [StructLayout(LayoutKind.Sequential, CharSet = CharSet.Unicode)]
            private struct CREDENTIAL
            {
                public uint Flags;
                public uint Type;
                public IntPtr TargetName;
                public IntPtr Comment;
                // FILETIME, as two DWORDs. A single long would be laid out differently on a 32-bit
                // runtime, and this struct has to match the one advapi32 is reading.
                public uint LastWrittenLow;
                public uint LastWrittenHigh;
                public uint CredentialBlobSize;
                public IntPtr CredentialBlob;
                public uint Persist;
                public uint AttributeCount;
                public IntPtr Attributes;
                public IntPtr TargetAlias;
                public IntPtr UserName;
            }

            [DllImport("advapi32.dll", EntryPoint = "CredWriteW", CharSet = CharSet.Unicode, SetLastError = true)]
            private static extern bool CredWrite(ref CREDENTIAL credential, uint flags);

            [DllImport("advapi32.dll", EntryPoint = "CredReadW", CharSet = CharSet.Unicode, SetLastError = true)]
            private static extern bool CredRead(string target, uint type, uint reserved, out IntPtr credential);

            [DllImport("advapi32.dll", EntryPoint = "CredDeleteW", CharSet = CharSet.Unicode, SetLastError = true)]
            private static extern bool CredDelete(string target, uint type, uint flags);

            [DllImport("advapi32.dll", EntryPoint = "CredFree")]
            private static extern void CredFree(IntPtr buffer);

            private const uint GENERIC = 1;
            private const uint PERSIST_LOCAL_MACHINE = 2;
            private const int NOT_FOUND = 1168;

            public static void Write(string target, string userName, byte[] blob)
            {
                IntPtr blobPointer = Marshal.AllocHGlobal(blob.Length);
                IntPtr targetPointer = Marshal.StringToCoTaskMemUni(target);
                IntPtr userNamePointer = Marshal.StringToCoTaskMemUni(userName);

                try
                {
                    Marshal.Copy(blob, 0, blobPointer, blob.Length);

                    CREDENTIAL credential = new CREDENTIAL();
                    credential.Type = GENERIC;
                    credential.TargetName = targetPointer;
                    credential.CredentialBlobSize = (uint) blob.Length;
                    credential.CredentialBlob = blobPointer;
                    credential.Persist = PERSIST_LOCAL_MACHINE;
                    credential.UserName = userNamePointer;

                    if (!CredWrite(ref credential, 0))
                    {
                        throw new InvalidOperationException("CredWriteW failed with " + Marshal.GetLastWin32Error());
                    }
                }
                finally
                {
                    // Zero the unmanaged copy rather than just releasing it to a heap the
                    // next allocation can read. This is NOT every plaintext copy in the process:
                    // the MemoryStream, the ASCII string and the byte[] that fed this buffer are
                    // managed, uncleared, and movable by the GC. Clearing what can be cleared is
                    // worth doing; believing it leaves no plaintext behind is not.
                    for (int i = 0; i < blob.Length; i++) { Marshal.WriteByte(blobPointer, i, 0); }
                    Marshal.FreeHGlobal(blobPointer);
                    Marshal.FreeCoTaskMem(targetPointer);
                    Marshal.FreeCoTaskMem(userNamePointer);
                }
            }

            public static byte[] Read(string target)
            {
                IntPtr credentialPointer;

                if (!CredRead(target, GENERIC, 0, out credentialPointer))
                {
                    int error = Marshal.GetLastWin32Error();

                    // `.Equals` rather than `==`, which C# would prefer. Pest's `strict()` arch
                    // preset lexes this whole PHP file, string contents included, and reads a `==`
                    // in here as PHP loose equality -- so the suite goes red on C# that is
                    // perfectly correct. There is no `===` to reach for in C#, so the comparison
                    // moves to a method call instead.
                    if (error.Equals(NOT_FOUND)) { return null; }

                    throw new InvalidOperationException("CredReadW failed with " + error);
                }

                try
                {
                    CREDENTIAL credential = (CREDENTIAL) Marshal.PtrToStructure(credentialPointer, typeof(CREDENTIAL));
                    byte[] blob = new byte[credential.CredentialBlobSize];

                    if (credential.CredentialBlobSize > 0)
                    {
                        Marshal.Copy(credential.CredentialBlob, blob, 0, (int) credential.CredentialBlobSize);
                    }

                    return blob;
                }
                finally
                {
                    CredFree(credentialPointer);
                }
            }

            public static void Delete(string target)
            {
                if (!CredDelete(target, GENERIC, 0))
                {
                    int error = Marshal.GetLastWin32Error();

                    // Asking for a credential to be gone when it already is is the state requested,
                    // not a failure. Negated `.Equals` for the reason given in `Read` above.
                    if (!error.Equals(NOT_FOUND))
                    {
                        throw new InvalidOperationException("CredDeleteW failed with " + error);
                    }
                }
            }
        }
        '@

        $target = [string] $env:ROBOT_COUNCIL_TARGET

        switch ([string] $env:ROBOT_COUNCIL_OPERATION) {
            'read' {
                $blob = [RobotCouncilCredential]::Read($target)
                if ($null -eq $blob) { exit 3 }
                [Console]::Out.Write([Convert]::ToBase64String($blob))
                exit 0
            }
            'write' {
                $buffer = New-Object System.IO.MemoryStream
                [Console]::OpenStandardInput().CopyTo($buffer)
                $blob = [Convert]::FromBase64String([Text.Encoding]::ASCII.GetString($buffer.ToArray()))
                [RobotCouncilCredential]::Write($target, [string] $env:ROBOT_COUNCIL_USERNAME, $blob)
                exit 0
            }
            'forget' {
                [RobotCouncilCredential]::Delete($target)
                exit 0
            }
            default { exit 4 }
        }
        POWERSHELL;

    /**
     * Whether the mechanism answered, memoized for the life of this instance.
     *
     * Null until `available()` has asked. One instance is resolved per command run by
     * `CredentialsServiceProvider`, so this is one probe per run rather than per call -- and it is
     * instance state rather than static state, so a test can get a fresh answer from a new object.
     */
    private ?bool $available = null;

    public function available(): bool
    {
        return $this->available ??= $this->probe();
    }

    public function describe(): string
    {
        return sprintf('Windows Credential Manager, under a `%s…` target (`cmdkey /list` lists it)', self::TARGET_PREFIX);
    }

    public function put(string $service, Credential $credential): void
    {
        $token = $credential->reveal();

        if (\strlen($token) > self::MAX_BLOB_BYTES) {
            throw new CredentialStoreFailed(sprintf(
                'The credential is longer than the %d bytes Windows Credential Manager accepts.',
                self::MAX_BLOB_BYTES,
            ));
        }

        $this->run('write', $this->target($service), $service, base64_encode($token));

        // Not the exit code. The other two stores read back because a store that reports success
        // and holds nothing is the failure that costs the most later, and that reasoning does not
        // depend on which platform is underneath.
        $stored = $this->get($service);

        if (! $stored instanceof Credential || ! $stored->equals($credential)) {
            throw new CredentialStoreFailed('The credential could not be stored in Windows Credential Manager.');
        }
    }

    /**
     * The credential for one service, or null when none is stored.
     *
     * **Null means "not enrolled", and nothing else.** A helper that exited for any other reason
     * raises `CredentialStoreFailed` rather than being reported as an empty Credential Manager.
     * That is a `RuntimeException`, which `ApiCommand` and `McpCommand` already catch around the
     * credential and render as an error, so no caller needs changing for it.
     *
     * @param  string  $service  The credential key.
     *
     * @throws CredentialStoreFailed When the mechanism failed, as opposed to holding nothing.
     */
    public function get(string $service): ?Credential
    {
        [$exitCode, $output, $errors] = $this->readThroughPipe($this->target($service));

        // A machine that is simply not enrolled. The only answer that is not a fault.
        if ($exitCode === self::NOT_FOUND) {
            return null;
        }

        // **Anything else non-zero is a broken mechanism, and saying so is the whole point of
        // `NOT_FOUND` existing.** `probe()` already demands exactly that code; collapsing every
        // other code into null here threw the distinction away at the one place a caller could act
        // on it. Exit 1 is a refused `Add-Type` or a thrown script, exit 4 is the script's
        // `default` branch, and neither means "no credential".
        //
        // `available()` is memoized for the life of the instance and one instance is resolved per
        // command run, so the probe answers once, early -- a machine can pass it and then fail
        // every later call. Reported as "not enrolled", the remedy an operator reaches for is to
        // enroll again, which asks the service for a *new* credential and stores it through the
        // same broken mechanism.
        if ($exitCode !== 0) {
            // Exit 1 alone covers three faults -- `Add-Type` refused, the script threw, or
            // `CredReadW` failed with something other than `ERROR_NOT_FOUND` -- and only stderr
            // tells them apart. It is already in hand, so naming it costs nothing.
            throw new CredentialStoreFailed(rtrim(sprintf(
                'Windows Credential Manager could not be read (the helper exited %d). %s',
                $exitCode,
                $this->firstLine($errors),
            )));
        }

        $decoded = base64_decode(trim($output), true);

        // **An accepted gap, stated rather than claimed impossible.** An earlier version of this
        // comment said the script had no path here. It has: an entry whose blob is zero bytes
        // makes `Read()` return an empty array rather than null, so the script writes an empty
        // string and exits 0. Measured on 2026-09-21 against a throwaway target. `probe()`'s
        // docblock names a second: a host that prefixed or mangled stdout would also land here.
        //
        // Both read as "not enrolled", which is the residue of the defect this method otherwise
        // closes. Null rather than a throw, because an empty blob is not a credential and there is
        // no exit code to name -- but it is a gap, not an impossibility.
        if ($decoded === false || $decoded === '') {
            return null;
        }

        return new Credential($decoded);
    }

    public function forget(string $service): void
    {
        // A missing credential is not an error here, and the script already treats it as the state
        // being asked for.
        $this->run('forget', $this->target($service));
    }

    /**
     * The Credential Manager target a key is filed under.
     *
     * Delegates to `WindowsCredentialTarget`, which holds the derivation and the measurement behind
     * its case-sensitive digest. **Nothing is derived here**, so this store and
     * `WindowsFfiCredentialStore` cannot disagree about where a credential lives -- a disagreement
     * that would surface as a machine losing its enrollment on a `php.ini` change, with no error to
     * read. A test asserts the two return identical targets.
     *
     * @param  string  $service  The credential key, which nothing here parses.
     * @return string The target name.
     */
    public function target(string $service): string
    {
        return WindowsCredentialTarget::for($service);
    }

    /**
     * Read one credential with the child's stdout on a real pipe.
     *
     * **This is the one call that does not go through `Symfony\Component\Process\Process`, and
     * the reason is that on Windows `Process` cannot give it a pipe.** `WindowsPipes` redirects
     * the child's stdout into `sf_proc_NN.out` under `sys_get_temp_dir()` -- its documented
     * workaround for PHP bug #51800, where reading a large output from a pipe hangs -- and
     * `getDescriptors()` offers no way out: `Process::getDescriptors()` builds the pipes with
     * `!$outputDisabled || $hasCallback`, so a callback forces the file on rather than off, and
     * disabling output makes the answer unreadable. This call returns the credential on stdout, so
     * with `Process` it would land in a file, in a directory `TMP` selects, that outlives the
     * process.
     *
     * The bug that workaround exists for does not reach this call, and that was measured rather
     * than assumed. A credential is bounded by `MAX_BLOB_BYTES`, so the largest possible output is
     * 3,416 base64 characters. Read through a real pipe on 2026-09-21: 64, 3,416, 8,192 and 65,536
     * characters each came back complete in 222-642ms, with no file created anywhere.
     *
     * `put()`, `forget()` and `probe()` stay on `Process`, which keeps its timeout handling where
     * it can be had. None of them returns a credential on stdout: the one `put()` sends travels on
     * stdin, which `AbstractPipes` writes to a real pipe on every platform, and `probe()` reads a
     * target nothing is filed at. `put()` reaches this method anyway, through the `get()` it calls
     * to read its own write back.
     *
     * @param  string  $target  The Credential Manager target to read.
     * @return array{0: int, 1: string, 2: string} The exit code, stdout, and stderr.
     */
    private function readThroughPipe(string $target): array
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];

        $handle = @proc_open(
            $this->arguments(),
            $descriptors,
            $pipes,
            null,
            [
                'ROBOT_COUNCIL_OPERATION' => 'read',
                'ROBOT_COUNCIL_TARGET' => $target,
                'ROBOT_COUNCIL_USERNAME' => '',
            ] + $this->inheritedEnvironment(),
            ['bypass_shell' => true, 'suppress_errors' => true],
        );

        if (! \is_resource($handle)) {
            throw new CredentialStoreFailed('Windows Credential Manager could not be reached.');
        }

        fclose($pipes[0]);

        // **The bound is a status poll, not a stream timeout, and that is measured rather than
        // preferred.** Three mechanisms were tried on 2026-09-21 and two do not work on a Windows
        // pipe:
        //
        // - `stream_set_blocking($pipe, false)` returns **false** and has no effect. A read against
        //   a child sleeping five seconds returned after 5.27s.
        // - `stream_select()` with a two-second timeout returned **2 immediately**, reporting both
        //   pipes readable when neither was, so it cannot serve as a readiness check either.
        // - `proc_get_status()` does not block, so polling it against a wall-clock deadline is a
        //   bound that actually binds.
        //
        // Reading only after the child has exited is what makes that safe: at that point both
        // streams are at EOF and come back immediately. It depends on the child being able to write
        // everything without the parent draining, and **both streams have to clear that bar, not
        // just stdout** -- stderr has its own buffer, and a fault is exactly when it gets used.
        // `robot-council/cli#58` is where that was measured; the figures are below.
        //
        // **The threshold is the same on both streams**, and `PIPE_BUFFER_BYTES` records it:
        // 4,096 bytes fit and the child exits, 8,192 blocks, identical for stdout and stderr.
        //
        // **stdout.** The largest this call can return is 3,416 characters, because
        // `MAX_BLOB_BYTES` bounds what `put()` will store. A blob larger than that, written by
        // something other than this store, makes the child block and this loop time out. That is a
        // bounded failure with a message, not a hang.
        //
        // **stderr, which is the half #58 asked about.** The largest a real failure produced is
        // **744 bytes**, against the 4,096 that fit. The concern behind the ticket was that
        // `Add-Type` emits a diagnostic per compiler error with source context and nothing bounds
        // how many -- measured, that is not what happens:
        //
        // - 1, 3, 10 and 30 distinct C# errors all produced about 732 bytes. PowerShell reports the
        //   **first** `Add-Type` diagnostic and a couple of source lines, not one record per error.
        // - `$ErrorActionPreference = 'Stop'`, the script's first line, is the structural reason.
        //   It makes the first error terminating, so there is no second one to report. Removing it
        //   is what would make this bound stop holding.
        // - A missing `TEMP`, the other fault on record, produced 361 bytes.
        //
        // **And the caller cannot inflate it.** The script is fixed at 5,881 characters and the one
        // caller-controlled input is the target, which travels in the environment. Targets of 6,000
        // characters, and targets built of newlines, quotes, `$(...)`, format specifiers and
        // non-ASCII, all produced **zero** bytes of stderr: the target reaches a .NET method that
        // either succeeds or throws a message naming an error number, and never reaches a formatter
        // that would echo it.
        //
        // So stderr cannot approach the buffer, and this loop does not drain it. If the script ever
        // grows a second error path, or loses `'Stop'`, that is the assumption to re-measure.
        $deadline = microtime(true) + self::TIMEOUT_SECONDS;

        while (proc_get_status($handle)['running']) {
            if (microtime(true) >= $deadline) {
                proc_terminate($handle);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($handle);

                throw new CredentialStoreFailed(sprintf(
                    'Windows Credential Manager did not answer within %d seconds.',
                    self::TIMEOUT_SECONDS,
                ));
            }

            // Short enough not to add noticeable latency to a call that takes about 650ms, long
            // enough not to spin a core while waiting for it. `Sleep` rather than `usleep()`,
            // which the `strict()` arch preset forbids across `App\` -- and which a test could
            // not have faked anyway.
            Sleep::for(10)->milliseconds();
        }

        $output = (string) stream_get_contents($pipes[1]);
        $errors = (string) stream_get_contents($pipes[2]);

        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($handle), $output, $errors];
    }

    /**
     * The first line of what the helper wrote on stderr, trimmed, for a message.
     *
     * One line rather than all of it: `Add-Type`'s failures run to several hundred bytes of
     * PowerShell formatting, and the first line carries the cause. Nothing here can hold a
     * credential -- the value travels on stdout, and this is the other stream.
     *
     * @param  string  $errors  What the child wrote on stderr.
     * @return string The first non-empty line, or an empty string.
     */
    private function firstLine(string $errors): string
    {
        foreach (preg_split('/\R/', $errors) ?: [] as $line) {
            $line = trim($line);

            if ($line !== '') {
                return $line;
            }
        }

        return '';
    }

    /**
     * The environment to hand the child, since `proc_open` replaces rather than merges.
     *
     * `Process::start()` merges what it is given over `getDefaultEnv()`. Passing an array to
     * `proc_open` does not: it becomes the child's entire environment. `powershell.exe` needs
     * `SystemRoot`, `PATH` and `TEMP` to start and to run its compiler, so they are carried
     * explicitly rather than by luck.
     *
     * @return array<string, string> The inherited environment.
     */
    private function inheritedEnvironment(): array
    {
        // `getenv()` with no argument returns every variable as a string, so this is the whole
        // inherited environment with nothing to filter.
        return getenv();
    }

    /**
     * Where `powershell.exe` is: a literal path, taking nothing from the environment.
     *
     * **The credential is piped to whatever this returns, so the answer must not be something a
     * caller can move.** `PATH` was never used, for the reason `KeychainStore` names
     * `/usr/bin/security` absolutely. `SystemRoot` was, and it is no better: measured on
     * 2026-09-21, `SystemRoot=C:\evil` makes `getenv('SystemRoot')` return `C:\evil`, so a planted
     * `C:\evil\System32\WindowsPowerShell\v1.0\powershell.exe` would have received the token on
     * stdin. `InstallationChoice` documents a harness's MCP configuration as setting variables in
     * an `env` block for this process, which makes the environment a third-party input rather than
     * only the user's.
     *
     * Writing under `C:\Windows\System32` needs administrator rights, which an actor who can only
     * set an environment variable does not have -- so a literal path removes the vector rather
     * than narrowing it.
     *
     * **The cost, stated rather than hidden: a Windows installed anywhere else is not supported.**
     * That machine finds nothing executable here, `available()` returns false, and `UserFileStore`
     * takes over -- which is exactly where it was before this store existed. A non-default
     * `%SystemRoot%` is rare enough that reading it back to support them would reintroduce the
     * vector for everyone else.
     *
     * @return string The absolute path, which may not exist -- `available()` is what checks.
     */
    private function powershell(): string
    {
        return self::INTERPRETER;
    }

    /**
     * Run the mechanism once, and report whether it reached a verdict.
     *
     * Checked rather than assumed, because every part of this can be missing on a machine that is
     * still recognizably Windows: `Add-Type` is refused under Constrained Language Mode, its
     * compiler needs a writable `TEMP`, and Server Core installs vary. A machine where any of that
     * fails must fall through to `UserFileStore` rather than fail to enroll, so the probe asks the
     * mechanism instead of asking the operating system's name.
     *
     * @return bool Whether Credential Manager can be used from here.
     */
    private function probe(): bool
    {
        if (PHP_OS_FAMILY !== 'Windows' || ! is_executable($this->powershell())) {
            return false;
        }

        // **Nothing in a probe may throw.** The whole point is to answer "can this be used", and
        // the machines it exists to protect are exactly the ones where the asking breaks:
        // `random_bytes()` can raise, the environment block can be refused as too large, and
        // `Process::run()` raises `ProcessTimedOutException` rather than returning an exit code
        // when the bound fires. `Credentials::store()` does not guard this call, and neither
        // `ApiCommand` nor `McpCommand` catches anything but `RuntimeException`, so an escaping
        // throwable would reach Collision instead of falling through to `UserFileStore`.
        try {
            $probe = $this->run('read', WindowsCredentialTarget::probe());
        } catch (Throwable) {
            return false;
        }

        // `NOT_FOUND` rather than merely "did not fail". Nothing is filed at this target, so a
        // working mechanism has exactly one right answer here, and demanding it rules out a call
        // that exited 0 without reaching `CredReadW` at all.
        //
        // What this does NOT exercise is the output path: the not-found branch writes nothing to
        // stdout, so a host that prefixed or mangled output would pass here and then return null
        // from every `get()`. Writing a sentinel to prove the read path would mean writing to the
        // user's real credential store on every probe, which is a worse trade.
        return $probe->getExitCode() === self::NOT_FOUND;
    }

    /**
     * Run one operation, and hand back the finished process.
     *
     * **The script travels as one inline argument rather than a temporary file.** As a file its
     * directory came from `TMP` -- measured on 2026-09-21, setting `TMP` to a directory of the
     * caller's choosing makes `sys_get_temp_dir()` return it -- and there was a window between
     * writing the file and `powershell.exe` opening it, during which it could be replaced. Inline
     * removes the script file and that window.
     *
     * **It does not make the call write nothing.** `Symfony\Component\Process\Process` redirects
     * the child's stdout and stderr into `sf_proc_NN.out` and `.err` under `sys_get_temp_dir()`,
     * as its documented workaround for PHP bug #51800, and those are appended to the command line
     * rather than being this store's doing. What changed here is the script, not every file.
     *
     * `-EncodedCommand` is still not used. Base64 of UTF-16 inflates this script to 15,684
     * characters, and because that form contains none of `"`, `!`, `%`, `^` or a newline it is
     * **not** hoisted into an environment variable -- it stays in the `cmd.exe` line and meets the
     * 8191-character limit, bisected on 2026-09-21 at 8039 passing and 8043 failing, with exit 1
     * and nothing on either stream. The inline form is hoisted instead, which is why it fits and
     * why `ENVIRONMENT_BUDGET` rather than a command-line length is what guards it.
     *
     * **`-ExecutionPolicy Bypass` is gone, and its absence is load-bearing rather than tidy.**
     * Execution policy governs script *files*, not `-Command`. Measured: under both `Restricted`
     * and `AllSigned` this invocation ran and returned its verdict, while the same script as a
     * `-File` was refused with "cannot be loaded". So the inline form works on locked-down
     * machines where the file form could not, and a flag that reads like a bypass is no longer
     * being requested.
     *
     * The script carries no credential either way -- that arrives on stdin -- so what moves here
     * is the attack surface around the script, not around the token.
     *
     * @param  string  $operation  The script's operation: `read`, `write`, or `forget`.
     * @param  string  $target  The Credential Manager target to act on.
     * @param  string  $username  What to record in the credential's user name field, which the
     *                            Credential Manager UI shows and nothing here reads back.
     * @param  string|null  $input  What to write on the process's stdin, which is how the
     *                              credential travels and the reason it is in no command line.
     *                              **Marked sensitive, and that attribute is load-bearing.** PHP
     *                              captures function arguments into every exception trace while
     *                              `zend.exception_ignore_args` is `0`, which is its default and
     *                              what this machine runs, and Collision prints string arguments
     *                              up to 1000 characters. `Credential` keeps the token out of
     *                              `put()`'s frame by being an object; this parameter would have
     *                              put it straight back into the next frame down, base64-encoded,
     *                              which is transport encoding and not protection. Verified: with
     *                              the attribute the frame renders
     *                              `Object(SensitiveParameterValue)`, without it the token.
     * @return Process The finished process.
     */
    private function run(
        string $operation,
        string $target,
        string $username = '',
        #[SensitiveParameter]
        ?string $input = null,
    ): Process {
        $process = new Process($this->arguments(), timeout: self::TIMEOUT_SECONDS);

        // Environment rather than argv: these are not secret, but they are caller-controlled, and
        // an environment variable has no quoting for a service URL to break out of. It also keeps
        // them clear of the `cmd.exe` wrapper above, which expands `!` on its own.
        $process->setEnv([
            'ROBOT_COUNCIL_OPERATION' => $operation,
            'ROBOT_COUNCIL_TARGET' => $target,
            'ROBOT_COUNCIL_USERNAME' => $username,
        ]);

        if ($input !== null) {
            $process->setInput($input);
        }

        // **Starting the process can raise a `LogicException`, which every caller would miss.**
        // `Process::start()` calls `validateWindowsEnvBlockSize()`, and the hoisted script is part
        // of what it measures, so a machine with a large environment of its own is refused with
        // `Symfony\…\InvalidArgumentException` -- which extends `\InvalidArgumentException`, not
        // `RuntimeException`. `ApiCommand` and `McpCommand` catch only `RuntimeException` around
        // the credential, so it would travel past both to Collision. Rewritten here into the one
        // exception type every caller of this class already handles.
        try {
            $process->run();
        } catch (Throwable $throwable) {
            throw new CredentialStoreFailed('Windows Credential Manager could not be reached.', $throwable->getCode(), $throwable);
        }

        return $process;
    }

    /**
     * The command this store runs, as `Process` takes it.
     *
     * Public so a test can assert on it without running anything: that the interpreter is the
     * literal one, that the script is passed inline, and that no `-File` path is handed over.
     *
     * @return non-empty-list<string> The interpreter and its arguments.
     */
    public function arguments(): array
    {
        return [$this->powershell(), '-NoProfile', '-NonInteractive', '-Command', self::SCRIPT];
    }
}
