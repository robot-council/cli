<?php

declare(strict_types=1);

namespace App\Support\Credentials;

use Symfony\Component\Process\Process;

/**
 * Windows Credential Manager, through a `CredWriteW` call made from PowerShell.
 *
 * **The token is written on stdin, never as an argument**, which is the whole reason this is not
 * three lines of `cmdkey`. `cmdkey /generic:… /pass:<token>` puts the credential in the process's
 * argv, where any process running as this user can read it out of
 * `Get-CimInstance Win32_Process`. That is the exposure `KeychainStore` goes to some trouble to
 * avoid on macOS, and Windows offers no stdin equivalent for `cmdkey` -- so `cmdkey` is not used at
 * all. The script below is handed to `powershell.exe` as a file, which carries no secret, and the
 * credential arrives on stdin. `run()` records why the script travels that way rather than as an
 * `-EncodedCommand`.
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
     */
    public const string TARGET_PREFIX = 'robot-council:';

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
     * A target this store never writes, used to exercise the mechanism in `available()`.
     *
     * **It deliberately does not begin with `TARGET_PREFIX`.** A service key is an opaque string
     * that nothing here parses, so every target `target()` can produce is `TARGET_PREFIX` followed
     * by *anything* -- including a leading colon. A probe target sharing that prefix could
     * therefore be occupied by a real credential, and `available()` would read someone's stored
     * token as a broken mechanism. `robot-council-probe:` is one character different and outside
     * the whole keyspace.
     */
    private const string PROBE_TARGET = 'robot-council-probe:availability';

    /**
     * The largest blob `CredWriteW` accepts, in bytes.
     *
     * `CRED_MAX_CREDENTIAL_BLOB_SIZE`. Checked here so that an over-long credential fails saying
     * so, rather than through a read-back that only reports the value did not land.
     */
    private const int MAX_BLOB_BYTES = 2560;

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
                    // The blob is this process's only plaintext copy, so it is cleared rather than
                    // just released back to a heap that the next allocation can read.
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
                    // not a failure. `!…Equals` for the reason given in `Read` above.
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

    public function get(string $service): ?Credential
    {
        $read = $this->run('read', $this->target($service));

        // `NOT_FOUND` is the expected answer for a machine that is simply not enrolled; anything
        // else non-zero is a broken mechanism, and both mean there is no credential to hand back.
        if ($read->getExitCode() !== 0) {
            return null;
        }

        $decoded = base64_decode(trim($read->getOutput()), true);

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
     * The Credential Manager target a service is filed under.
     *
     * Keyed on the service so a machine enrolled against two deployments holds two credentials,
     * matching the other stores.
     *
     * @param  string  $service  The service's base URL.
     * @return string The target name.
     */
    public function target(string $service): string
    {
        return self::TARGET_PREFIX.$service;
    }

    /**
     * Where `powershell.exe` is, built from `SystemRoot` rather than looked up on `PATH`.
     *
     * `PATH` is writable by the user and searched in order, so resolving there would let anything
     * that dropped a `powershell.exe` earlier in it receive the credential on stdin.
     * `KeychainStore` names `/usr/bin/security` absolutely for the same reason.
     *
     * @return string The absolute path, which may not exist -- `available()` is what checks.
     */
    private function powershell(): string
    {
        $root = getenv('SystemRoot');

        if (! \is_string($root) || $root === '') {
            $root = 'C:\Windows';
        }

        return rtrim($root, '\\/').'\System32\WindowsPowerShell\v1.0\powershell.exe';
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

        $probe = $this->run('read', self::PROBE_TARGET);

        // `NOT_FOUND` rather than merely "did not fail". Nothing is filed at this target, so a
        // working mechanism has exactly one right answer here, and demanding it rules out a call
        // that exited 0 without reaching `CredReadW` at all.
        return $probe->getExitCode() === self::NOT_FOUND;
    }

    /**
     * Run one operation, and hand back the finished process.
     *
     * **The script travels as a file rather than as `-EncodedCommand`, and that is a measured
     * ceiling rather than a preference.** Symfony's `Process` ends `prepareWindowsCommandLine()`
     * by wrapping every Windows command in `cmd.exe /V:ON /E:ON /D /C (…)`, whatever
     * `bypass_shell` says, so the limit that applies is `cmd.exe`'s 8191 characters and not
     * `CreateProcess`'s 32767. Measured on 2026-09-21 by bisecting the length: a total command line
     * of 8039 characters ran, 8043 did not, and the failure is **exit 1 with nothing on either
     * stream** -- nothing anywhere says the command was too long. The same payloads ran through
     * `proc_open()` directly and through `powershell.exe` invoked by hand, which is what pins the
     * ceiling on the wrapper rather than on Windows. This script encodes to about 13,700
     * characters, so it was never going to fit.
     *
     * The file holds no credential. It is written under the user's own temporary directory with a
     * random name and removed once the call returns, and anything able to tamper with it in between
     * already runs as this user -- the same boundary every store here operates inside.
     *
     * `-ExecutionPolicy Bypass` is needed on this path, where an encoded command would not have
     * needed it: `-File` runs a script, and a script is what execution policy governs. A Group
     * Policy-enforced policy overrides the flag, and on such a machine `available()` sees the
     * failure and `UserFileStore` takes over. That case was not reproduced here, since setting a
     * machine policy needs rights this session did not have, so it is covered by the probe rather
     * than by a measurement.
     *
     * @param  string  $operation  The script's operation: `read`, `write`, or `forget`.
     * @param  string  $target  The Credential Manager target to act on.
     * @param  string  $username  What to record in the credential's user name field, which the
     *                            Credential Manager UI shows and nothing here reads back.
     * @param  string|null  $input  What to write on the process's stdin, which is how the
     *                              credential travels and the reason it is in no command line.
     * @return Process The finished process.
     */
    private function run(string $operation, string $target, string $username = '', ?string $input = null): Process
    {
        $script = $this->writeScript();

        try {
            $process = new Process(
                [$this->powershell(), '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', $script],
                timeout: self::TIMEOUT_SECONDS,
            );

            // Environment rather than argv: these are not secret, but they are caller-controlled,
            // and an environment variable has no quoting for a service URL to break out of. It also
            // keeps them clear of the `cmd.exe` wrapper above, which expands `!` on its own.
            $process->setEnv([
                'ROBOT_COUNCIL_OPERATION' => $operation,
                'ROBOT_COUNCIL_TARGET' => $target,
                'ROBOT_COUNCIL_USERNAME' => $username,
            ]);

            if ($input !== null) {
                $process->setInput($input);
            }

            $process->run();

            return $process;
        } finally {
            @unlink($script);
        }
    }

    /**
     * Put the script somewhere `powershell.exe` can open it.
     *
     * The name is random rather than derived from anything, so nothing can sit waiting at a path
     * this is about to write. The `.ps1` extension is not decoration: `-File` refuses a script
     * without one.
     *
     * @return string The path written.
     *
     * @throws CredentialStoreFailed When the script could not be written.
     */
    private function writeScript(): string
    {
        $directory = rtrim(sys_get_temp_dir(), '/'.\DIRECTORY_SEPARATOR);

        $path = sprintf('%s%s%s.ps1', $directory, \DIRECTORY_SEPARATOR, 'robot-council-'.bin2hex(random_bytes(16)));

        if (file_put_contents($path, self::SCRIPT) === false) {
            throw new CredentialStoreFailed(sprintf('Could not write the Credential Manager script to `%s`.', $path));
        }

        return $path;
    }
}
