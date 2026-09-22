<?php

declare(strict_types=1);

/**
 * Windows Credential Manager, reached through `advapi32.dll` in-process with PHP's FFI extension.
 *
 * Split the same way `WindowsCredentialStoreTest` is. The **structural** tests run everywhere and
 * guard properties a plausible simplification would take away -- the two stores deriving one target,
 * the token staying out of exception traces, the C declaration matching the structure `advapi32`
 * reads. The **round-trip** tests need a machine where FFI is usable and are gated on the store
 * saying so.
 *
 * **Which path a run exercised is stated rather than implied.** A machine can have both mechanisms,
 * one, or neither, and a file of skips reads exactly like a file of passes. `it('reports which
 * Windows mechanisms this machine has')` prints the answer on every run, and the two availability
 * tests assert that a machine which *could* use a mechanism does.
 *
 * What is NOT here: an in-suite assertion that no `powershell.exe` is created. A watcher polling
 * `Win32_Process` was measured on 2026-09-21 to miss a sub-second process entirely, so that check
 * would go green whether or not the property held. It is verified on the pull request instead, by
 * observing both paths over equal wall-clock windows -- 13 calls producing 13 processes against
 * 93,850 producing none.
 *
 * @command  vendor/bin/pest --compact tests/Feature/WindowsFfiCredentialStoreTest.php
 */

use App\Support\Credentials\Credential;
use App\Support\Credentials\Credentials;
use App\Support\Credentials\CredentialStoreFailed;
use App\Support\Credentials\WindowsCredentialStore;
use App\Support\Credentials\WindowsCredentialTarget;
use App\Support\Credentials\WindowsFfiCredentialStore;
use Symfony\Component\Process\Process;

const FFI_TOKEN = 'rcouncil_1|FfIsEcReTvAlUe0123456789abcdef';

/**
 * A constant of the FFI store, read out of the class rather than copied into the test.
 *
 * Named apart from `WindowsCredentialStoreTest`'s `constantOf()`: Pest loads every test file into
 * one process, so two files declaring one function name is a fatal redeclaration.
 *
 * @param  string  $name  The constant to read.
 * @return string Its value.
 */
function ffiConstantOf(string $name): string
{
    $value = new ReflectionClass(WindowsFfiCredentialStore::class)->getConstant($name);

    if (! is_string($value)) {
        throw new RuntimeException(sprintf('`WindowsFfiCredentialStore::%s` is not a string.', $name));
    }

    return $value;
}

/**
 * An integer constant of the FFI store, narrowed rather than cast. Private constants included:
 * `ReflectionClass::getConstant()` reads them, which is what lets the two mechanisms be compared
 * without making either one's internals public.
 *
 * @param  string  $name  The constant to read.
 * @return int Its value.
 */
function ffiIntConstantOf(string $name): int
{
    $value = new ReflectionClass(WindowsFfiCredentialStore::class)->getConstant($name);

    if (! is_int($value)) {
        throw new RuntimeException(sprintf('`WindowsFfiCredentialStore::%s` is not an int.', $name));
    }

    return $value;
}

/**
 * The PowerShell path's script, so the two mechanisms' constants can be compared in one assertion.
 *
 * @return string The script.
 */
function powerShellScript(): string
{
    $value = new ReflectionClass(WindowsCredentialStore::class)->getConstant('SCRIPT');

    if (! is_string($value)) {
        throw new RuntimeException('`WindowsCredentialStore::SCRIPT` is not a string.');
    }

    return $value;
}

/**
 * Skip unless this machine can actually reach Credential Manager through FFI.
 *
 * Memoized across the file. Unlike the PowerShell store's probe this is cheap -- no process, no
 * compiler -- but the answer is still unchanging, and asking once keeps the two files symmetrical.
 */
function requiresFfiCredentialManager(): bool
{
    static $available = null;

    $available ??= new WindowsFfiCredentialStore()->available();

    return ! $available;
}

/**
 * Whether this machine could use FFI at all, asked without going through the store.
 *
 * **Deliberately not the store's own `available()`**: the test that asserts the store IS available
 * wherever it could be must not have the store's answer as its own condition, or it asserts nothing.
 * This asks the two things the store needs and does not ask the third -- whether `advapi32` actually
 * answers -- which is exactly the gap the assertion exists to close.
 */
function couldUseFfi(): bool
{
    return PHP_OS_FAMILY === 'Windows' && extension_loaded('FFI');
}

beforeEach(function (): void {
    $this->store = new WindowsFfiCredentialStore;
    $this->service = 'https://ffi-fleet-'.bin2hex(random_bytes(6)).'.example.test';
    $this->other = 'https://ffi-other-'.bin2hex(random_bytes(6)).'.example.test';

    // Every key a test writes goes in here, and `afterEach` removes exactly these. Credential
    // Manager is the developer's real one.
    $this->written = [$this->service, $this->other];
});

afterEach(function (): void {
    if (requiresFfiCredentialManager()) {
        return;
    }

    foreach ($this->written as $key) {
        $this->store->forget($key);
    }
});

it('reports which Windows mechanisms this machine has', function (): void {
    $ffi = new WindowsFfiCredentialStore()->available();
    $powershell = new WindowsCredentialStore()->available();

    // Printed rather than asserted, because every combination is a legitimate machine. What it
    // prevents is reading a run of this file without knowing which half of it actually executed.
    fwrite(STDERR, sprintf(
        "\n  [windows credential mechanisms] os=%s ffi-extension=%s ffi-store=%s powershell-store=%s selected=%s\n",
        PHP_OS_FAMILY,
        extension_loaded('FFI') ? 'loaded' : 'absent',
        $ffi ? 'available' : 'unavailable',
        $powershell ? 'available' : 'unavailable',
        new Credentials(Credentials::candidates())->store()::class,
    ));

    // The invariant behind that line: `store()` returns the first candidate that says it can be
    // used, so whatever it picked must say so. On a machine with neither Windows mechanism this is
    // `UserFileStore`, which is the point of it being last.
    expect(new Credentials(Credentials::candidates())->store()->available())->toBeTrue();
});

it('derives exactly the same target as the PowerShell store', function (): void {
    $ffi = new WindowsFfiCredentialStore;
    $powershell = new WindowsCredentialStore;

    // **The structural guarantee the whole two-store design rests on.** Both reach one Credential
    // Manager, and which one a machine uses depends on its `php.ini`. A fork of this derivation
    // would make a machine that gained or lost FFI stop finding what it had already stored, and
    // report it as "not enrolled" -- there is no error to read, because a target nobody wrote to
    // and a machine that was never enrolled are the same answer.
    foreach ([':leading-colon', 'https://x.test/a b', 'with"quote', "with\nnewline", "caf\u{00e9}", ''] as $service) {
        expect($ffi->target($service))->toBe($powershell->target($service))
            ->and($ffi->target($service))->toBe(WindowsCredentialTarget::for($service));
    }
});

it('carries the service key without parsing it', function (): void {
    $store = new WindowsFfiCredentialStore;

    foreach ([':leading-colon', 'https://x.test/a b', 'with"quote', "with\nnewline", ''] as $service) {
        expect($store->target($service))->toStartWith(WindowsCredentialTarget::PREFIX.$service);
    }
});

it('gives two keys that differ only in case two different targets', function (): void {
    $store = new WindowsFfiCredentialStore;

    // Compared with both sides lowercased, which is the point: Credential Manager matches target
    // names case-insensitively, so comparing the targets as written would only assert that PHP
    // string comparison is case-sensitive. Measured through FFI on 2026-09-22, the collapse is
    // `advapi32`'s own behavior and not the PowerShell path's.
    expect(strtolower($store->target('https://x.test/FleetA|claude')))
        ->not->toBe(strtolower($store->target('https://x.test/fleeta|claude')));
});

it('probes a target no service key can ever produce', function (): void {
    // `available()` reads a target under this prefix and requires "no such credential". If a real
    // credential could sit there, a machine enrolled against that key would have its own token read
    // as a broken mechanism.
    expect(WindowsCredentialTarget::PROBE_PREFIX)->not->toStartWith(WindowsCredentialTarget::PREFIX);
});

it('declares the Win32 BOOL as an int rather than a C99 bool', function (): void {
    // A Win32 `BOOL` is a 4-byte `int`. Declared as `bool`, PHP's FFI reads one byte of the return
    // register -- which happens to work for 0 and 1, so nothing would fail until it did. The whole
    // not-found path turns on distinguishing a false return from a true one.
    $header = ffiConstantOf('HEADER');

    expect($header)->toContain('int CredWriteW(')
        ->and($header)->toContain('int CredReadW(')
        ->and($header)->toContain('int CredDeleteW(')
        ->and($header)->not->toContain('bool Cred');
});

it('writes the same credential type and persistence as the PowerShell path', function (): void {
    // **Two constants that must match across the mechanisms, asserted where no machine is needed.**
    // The round-trip test that proves agreement is gated on having both mechanisms, so on ubuntu
    // and macOS nothing checks this at all. Comparing the FFI store's constants against the
    // PowerShell script's own literals runs everywhere.
    //
    // `Type` is load-bearing because `CredReadW` keys on it: a credential written as one type is
    // invisible to a read of another, so a mismatch makes each store blind to the other's entries.
    //
    // `Persist` is the one a round-trip could never catch. It decides whether the credential
    // survives a reboot, and both stores read back fine within a session whichever value they used
    // -- so this assertion is the only thing standing between a silent change here and a machine
    // that quietly forgets its enrollment on the next restart. Mutation testing named it: every
    // other mutant on this class is killed or explained, and this constant had nothing covering it.
    expect(powerShellScript())
        ->toContain(sprintf('private const uint GENERIC = %d;', ffiIntConstantOf('GENERIC')))
        ->toContain(sprintf('private const uint PERSIST_LOCAL_MACHINE = %d;', ffiIntConstantOf('PERSIST_LOCAL_MACHINE')));
});

it('keeps the credential out of every exception trace frame', function (): void {
    // **The defect this guards against has been paid for once already** on the PowerShell store.
    // PHP captures function arguments into every exception trace while `zend.exception_ignore_args`
    // is `0`, which is its default, and Collision prints string arguments up to 1000 characters.
    // `Credential` keeps the token out of `put()`'s frame by being an object; a plain `string`
    // parameter one frame down puts it straight back.
    $parameters = new ReflectionMethod(WindowsFfiCredentialStore::class, 'write')->getParameters();
    $blob = $parameters[2];

    expect($blob->getName())->toBe('blobBytes')
        ->and($blob->getAttributes(SensitiveParameter::class))->not->toBeEmpty();
});

// The candidate ORDER -- this store ahead of the PowerShell one, both ahead of the file fallback --
// is pinned in `UserFileStoreTest`, which already owns that list and states why the relative order
// of the two Windows stores is load-bearing. Asserting it in both files would be one property with
// two places to update.

it('is unavailable where FFI or Windows is not', function (): void {
    expect(new WindowsFfiCredentialStore()->available())->toBeFalse();
})->skip(couldUseFfi(...), 'This machine has FFI on Windows, so the store should be available.');

it('is available on a Windows machine whose FFI extension is loaded', function (): void {
    // **This is the test that fails when the whole feature silently stops working.** Every
    // round-trip test below is gated on `available()`, so a mechanism broken anywhere -- a header
    // that no longer parses, a renamed symbol, a changed error code -- turns the behavioral half of
    // this file into skips, and a suite of skips reads exactly like a suite of passes.
    //
    // It also pins the fact the whole path depends on: `ffi.enable` defaults to `preload`, under
    // which `FFI::cdef()` is refused outside a preloaded script -- except in the CLI SAPI, which is
    // the only SAPI this application runs in. If that ever stops being true, this goes red rather
    // than every machine quietly falling back to starting PowerShell.
    expect(new WindowsFfiCredentialStore()->available())->toBeTrue();
})->skip(fn (): bool => ! couldUseFfi(), 'No FFI extension on Windows here.');

it('lays the credential structure out the way advapi32 reads it', function (): void {
    // Eight pointers and five 32-bit fields under natural alignment: 80 bytes on a 64-bit build, 52
    // on a 32-bit one. A field added, removed, or reordered in the header changes this, and the
    // failure it would otherwise produce is `advapi32` reading the wrong offsets rather than an
    // error anyone could read.
    expect(new WindowsFfiCredentialStore()->structureSize())->toBe(PHP_INT_SIZE === 8 ? 80 : 52);
})->skip(fn (): bool => ! couldUseFfi(), 'No FFI extension on Windows here.');

it('names Credential Manager, so a developer can go and look', function (): void {
    expect(new WindowsFfiCredentialStore()->describe())
        ->toContain('Credential Manager')
        ->toContain('cmdkey /list')
        // Identical to the PowerShell store's: the credential is in the same place whichever
        // mechanism put it there, and a line that changed with a machine's `php.ini` would suggest
        // it had moved.
        ->toBe(new WindowsCredentialStore()->describe());
});

it('stores a credential and reads it back', function (): void {
    $this->store->put($this->service, new Credential(FFI_TOKEN));

    expect($this->store->get($this->service)?->reveal())->toBe(FFI_TOKEN);
})->skip(requiresFfiCredentialManager(...), 'FFI cannot reach Credential Manager on this machine.');

it('returns null for a service it holds nothing for', function (): void {
    expect($this->store->get($this->service))->toBeNull();
})->skip(requiresFfiCredentialManager(...), 'FFI cannot reach Credential Manager on this machine.');

it('keeps two services apart rather than overwriting one with the other', function (): void {
    $this->store->put($this->service, new Credential(FFI_TOKEN));
    $this->store->put($this->other, new Credential('a-different-credential'));

    expect($this->store->get($this->service)?->reveal())->toBe(FFI_TOKEN)
        ->and($this->store->get($this->other)?->reveal())->toBe('a-different-credential');
})->skip(requiresFfiCredentialManager(...), 'FFI cannot reach Credential Manager on this machine.');

it('does not answer for a key that differs only in case', function (): void {
    $mixed = 'https://ffi-case-'.bin2hex(random_bytes(4)).'.example.test/FleetA|claude';
    $lower = strtolower($mixed);

    $this->written[] = $mixed;
    $this->written[] = $lower;

    $this->store->put($mixed, new Credential(FFI_TOKEN));

    // The security property, not a formatting one: without a case-sensitive digest in the target,
    // this store hands one fleet's bearer token to a different fleet whose URL differs only in case.
    expect($this->store->get($mixed)?->reveal())->toBe(FFI_TOKEN)
        ->and($this->store->get($lower))->toBeNull();

    $this->store->put($lower, new Credential('a-different-credential'));

    expect($this->store->get($mixed)?->reveal())->toBe(FFI_TOKEN)
        ->and($this->store->get($lower)?->reveal())->toBe('a-different-credential');
})->skip(requiresFfiCredentialManager(...), 'FFI cannot reach Credential Manager on this machine.');

it('refuses an empty credential rather than appearing to store one', function (): void {
    // **`CredWriteW` accepts a zero-length blob and `CredReadW` hands it back as an empty string**,
    // measured on 2026-09-22 against a throwaway target. So the write succeeds, and `get()` reports
    // the result as "not enrolled" -- an empty blob is not a credential, and there is no error code
    // to name. The read-back in `put()` is what turns that into a loud failure instead of a caller
    // walking away believing a credential was stored.
    //
    // This is the branch the store's docblock calls an accepted gap, and until this test it was the
    // only part of `read()` and `get()` that no test entered: the mutation run listed both its
    // early returns as uncovered.
    expect(fn () => $this->store->put($this->service, new Credential('')))
        ->toThrow(CredentialStoreFailed::class);

    // And nothing readable is left behind, which is what makes the failure safe rather than partial.
    expect($this->store->get($this->service))->toBeNull();
})->skip(requiresFfiCredentialManager(...), 'FFI cannot reach Credential Manager on this machine.');

it('stores a service key at the length ceiling and refuses one past it', function (): void {
    // **Both sides of the boundary, against a real Credential Manager**, with the sizes taken from
    // the constant. The key travels as the credential's user name, which Windows caps at 512 UTF-16
    // code units -- so this is a ceiling on the fleet URL plus harness, not on anything cosmetic.
    $ceiling = WindowsCredentialTarget::MAX_USER_NAME_UNITS;
    $atCeiling = 'https://'.str_repeat('a', $ceiling - \strlen('https://.example.test')).'.example.test';

    expect(WindowsCredentialTarget::userNameUnits($atCeiling))->toBe($ceiling);

    $this->written[] = $atCeiling;
    $this->store->put($atCeiling, new Credential(FFI_TOKEN));

    // The half that would break if the ceiling were set one too low: a key exactly at it still
    // round-trips, target and all.
    expect($this->store->get($atCeiling)?->reveal())->toBe(FFI_TOKEN);

    $tooLong = $atCeiling.'x';

    // And the half that would break if it were one too high: refused before `CredWriteW`, with a
    // message naming the limit rather than Windows error 1734.
    expect(fn () => $this->store->put($tooLong, new Credential(FFI_TOKEN)))
        ->toThrow(CredentialStoreFailed::class, (string) $ceiling);
})->skip(requiresFfiCredentialManager(...), 'FFI cannot reach Credential Manager on this machine.');

it('stores a non-ASCII service key at the ceiling, which a byte-based rule would refuse', function (): void {
    // 512 kanji is 1,536 UTF-8 bytes and Windows stores it. This is the round trip that proves the
    // rule counts the right unit, rather than the arithmetic test alone.
    $key = str_repeat("\u{6771}", WindowsCredentialTarget::MAX_USER_NAME_UNITS);

    expect(\strlen($key))->toBeGreaterThan(WindowsCredentialTarget::MAX_USER_NAME_UNITS);

    $this->written[] = $key;
    $this->store->put($key, new Credential(FFI_TOKEN));

    expect($this->store->get($key)?->reveal())->toBe(FFI_TOKEN);
})->skip(requiresFfiCredentialManager(...), 'FFI cannot reach Credential Manager on this machine.');

it('forgets a credential, and forgetting an absent one is not an error', function (): void {
    $this->store->put($this->service, new Credential(FFI_TOKEN));

    expect($this->store->get($this->service))->not->toBeNull();

    $this->store->forget($this->service);

    expect($this->store->get($this->service))->toBeNull();

    // `CredDeleteW` reports `ERROR_NOT_FOUND` here, which is the state being asked for.
    $this->store->forget($this->service);

    expect($this->store->get($this->service))->toBeNull();
})->skip(requiresFfiCredentialManager(...), 'FFI cannot reach Credential Manager on this machine.');

it('stores a credential at the blob ceiling and refuses one byte more', function (): void {
    // Both sides of the boundary, with the sizes taken from the constant so the store and its test
    // cannot drift apart. Measured through FFI on 2026-09-22: 2,560 stores, 2,561 is refused inside
    // `CredWriteW` with Windows error 1783.
    $ceiling = WindowsCredentialTarget::MAX_BLOB_BYTES;

    $this->store->put($this->service, new Credential(str_repeat('x', $ceiling)));

    $stored = $this->store->get($this->service);

    expect($stored?->reveal())->toHaveLength($ceiling);

    $oneOver = fn () => $this->store->put($this->other, new Credential(str_repeat('x', $ceiling + 1)));

    // Refused before `CredWriteW` is reached, so the message names the size rather than reporting
    // Windows error 1783 -- which is what the API returns for an over-long blob, measured, and is
    // no use to anyone reading it.
    expect($oneOver)->toThrow(CredentialStoreFailed::class, sprintf('longer than the %d bytes', $ceiling));
})->skip(requiresFfiCredentialManager(...), 'FFI cannot reach Credential Manager on this machine.');

it('reads back what the PowerShell store wrote, and the other way round', function (): void {
    $powershell = new WindowsCredentialStore;

    // **The acceptance criterion this file exists for.** The two mechanisms write interchangeable
    // entries, so a machine that gains or loses FFI keeps its enrollment. Both directions, because
    // agreeing on the target is necessary and not sufficient -- the credential type, the persistence
    // flag and the blob encoding all have to match too, and a mismatch in any of them shows up as
    // one store reading null.
    $powershell->put($this->service, new Credential(FFI_TOKEN));

    expect($this->store->get($this->service)?->reveal())->toBe(FFI_TOKEN);

    $this->store->put($this->other, new Credential('written-through-ffi'));

    expect($powershell->get($this->other)?->reveal())->toBe('written-through-ffi');
})->skip(
    fn (): bool => requiresFfiCredentialManager() || ! new WindowsCredentialStore()->available(),
    'Both Windows mechanisms are needed to compare them, and this machine does not have both.',
);

it('writes nothing to the temporary directory when it reads a credential', function (): void {
    $decoy = sys_get_temp_dir().'/rc-ffi-decoy-'.bin2hex(random_bytes(6));
    $control = sys_get_temp_dir().'/rc-ffi-control-'.bin2hex(random_bytes(6));

    mkdir($decoy, 0700, true);
    mkdir($control, 0700, true);

    // **Driven in a subprocess, because PHP caches `sys_get_temp_dir()` on first use.** Redirecting
    // `TMP` inside this process does not move it.
    $run = static function (string $temporaryDirectory, string $mode, string $service): Process {
        $code = <<<'PHP'
            require $argv[1].'/vendor/autoload.php';
            fwrite(STDOUT, sys_get_temp_dir()."\n");
            $ffi = new App\Support\Credentials\WindowsFfiCredentialStore;
            if ($argv[3] === 'control') {
                // The PowerShell store's `put()` goes through `Symfony\…\Process`, which on Windows
                // spools a child's stdout and stderr into `sf_proc_NN.out` and `.err` under the
                // temporary directory. This is the positive control.
                (new App\Support\Credentials\WindowsCredentialStore)->put($argv[2], new App\Support\Credentials\Credential('control-token'));
                $ffi->forget($argv[2]);
            } else {
                $ffi->get($argv[2]);
            }
            PHP;

        $process = new Process(
            [PHP_BINARY, '-r', $code, \dirname(__DIR__, 2), $service, $mode],
            timeout: WindowsCredentialStore::TIMEOUT_SECONDS * 2,
        );

        $process->setEnv(['TMP' => $temporaryDirectory, 'TEMP' => $temporaryDirectory]);
        $process->run();

        return $process;
    };

    try {
        $subject = $run($decoy, 'subject', $this->service);

        // Control that the child saw the decoy. Without it an empty directory is indistinguishable
        // from a redirect that never took.
        $normalize = static fn (string $path): string => str_replace('\\', '/', $path);

        expect($normalize(trim($subject->getOutput())))->toBe($normalize($decoy))
            ->and($subject->getExitCode())->toBe(0);

        // **The positive control that makes the assertion below mean something, and it was missing
        // from the equivalent test on the PowerShell store.** That test asserts an empty directory
        // after a `get()` -- true, but `get()` writes nothing on *either* path now, so an
        // instrument that could see nothing at all would pass it identically. Here a run that is
        // known to spool proves the directory is watched.
        $spooling = $run($control, 'control', $this->service);

        expect($spooling->getExitCode())->toBe(0)
            ->and(glob($control.'/sf_proc_*') ?: [])->not->toBeEmpty();

        // Now the claim: reading through FFI leaves the directory exactly as it found it.
        expect(scandir($decoy))->toBe(['.', '..']);
    } finally {
        foreach ([$decoy, $control] as $directory) {
            array_map(unlink(...), glob($directory.'/*') ?: []);
            rmdir($directory);
        }
    }
})->skip(
    fn (): bool => requiresFfiCredentialManager() || ! new WindowsCredentialStore()->available(),
    'The control needs the PowerShell store, and this machine does not have both.',
);

it('keeps the token out of a real rendered trace, not just out of reflection', function (): void {
    // **The reflection test above asserts the attribute is present; this asserts it WORKS.** Those
    // are different claims, and only the second one is what protects the credential: PHP captures
    // function arguments into every exception trace while `zend.exception_ignore_args` is `0`, and
    // an attribute that stopped being honored would leave the reflection assertion green.
    //
    // Driven in a subprocess with `ffi.enable=0`, which makes `Advapi32::bind()` raise while
    // `write()` is still on the stack -- so `write()`'s frame, with its arguments, is genuinely in
    // the trace that gets rendered. Nothing else in this file reaches that state.
    $child = static function (string $mode, string $token): array {
        $code = <<<'PHP'
            require $argv[1].'/vendor/autoload.php';

            // The positive control: same shape, same call, no attribute. If this does not show the
            // token then the instrument cannot see one that IS in a frame, and the subject's
            // silence would mean nothing at all.
            function unguardedFrame(string $blobBytes): void {
                throw new RuntimeException('forced');
            }

            $token = $argv[2];

            try {
                if ($argv[4] === 'control') {
                    unguardedFrame($token);
                } else {
                    $store = new App\Support\Credentials\WindowsFfiCredentialStore;
                    $store->put($argv[3], new App\Support\Credentials\Credential($token));
                }
                fwrite(STDOUT, 'NOTHING THREW');
            } catch (Throwable $e) {
                $rendered = '';
                for ($current = $e; $current !== null; $current = $current->getPrevious()) {
                    foreach ($current->getTrace() as $frame) {
                        $rendered .= ($frame['function'] ?? '').print_r($frame['args'] ?? [], true);
                    }
                }
                fwrite(STDOUT, sprintf(
                    'ignore_args=%s frames=%s %s',
                    ini_get('zend.exception_ignore_args'),
                    $rendered === '' ? 'EMPTY' : 'PRESENT',
                    str_contains($rendered, $token) ? 'TOKEN IN A FRAME' : 'TOKEN ABSENT',
                ));
            }
            PHP;

        $process = new Process(
            [PHP_BINARY, '-d', 'ffi.enable=0', '-d', 'zend.exception_ignore_args=0', '-r', $code,
                \dirname(__DIR__, 2), $token, 'https://trace-'.bin2hex(random_bytes(4)).'.example.test', $mode],
            timeout: WindowsCredentialStore::TIMEOUT_SECONDS * 2,
        );

        $process->run();

        return [$process->getExitCode(), trim($process->getOutput())];
    };

    $token = 'rcouncil_1|TRACE-SENTINEL-'.bin2hex(random_bytes(12));

    // Control first, and it has to fail in the direction that proves the instrument works.
    [$controlExit, $control] = $child('control', $token);

    expect($controlExit)->toBe(0)
        ->and($control)->toContain('ignore_args=0')
        ->and($control)->toContain('frames=PRESENT')
        ->and($control)->toContain('TOKEN IN A FRAME');

    // The same machinery pointed at the store. `write()` is on the stack when the binding raises,
    // so its frame is in this trace -- carrying `Object(SensitiveParameterValue)` where the
    // credential would otherwise be.
    [$subjectExit, $subject] = $child('subject', $token);

    expect($subjectExit)->toBe(0)
        ->and($subject)->toContain('frames=PRESENT')
        ->and($subject)->toContain('TOKEN ABSENT')
        ->and($subject)->not->toContain('TOKEN IN A FRAME');
});

it('reports a disabled FFI extension as a store failure rather than an Error', function (): void {
    // **`FFI\Exception` extends `Error`, not `RuntimeException`.** `ApiCommand` and `McpCommand`
    // wrap credential resolution in `catch (RuntimeException)`, so without the conversion in
    // `Advapi32::translate()` this would sail past both and reach Collision, which renders a stack
    // trace. That is the same escape `robot-council/cli#35` paid for once, when
    // `InvalidArgumentException` from `Process::start()` left the PowerShell store the same way.
    //
    // Driven in subprocesses because the fault has to be real: `ffi.enable=0` is set on the child's
    // command line, which is the configuration most Windows machines actually ship with.
    $child = static function (string $ffiEnable): array {
        $code = <<<'PHP'
            require $argv[1].'/vendor/autoload.php';
            $store = new App\Support\Credentials\WindowsFfiCredentialStore;
            fwrite(STDOUT, 'available='.var_export($store->available(), true).' ');
            try {
                // Called directly rather than through `Credentials::store()`, which would never
                // hand back an unavailable store. The point is that the class is safe even when a
                // caller reaches past that.
                $got = $store->get($argv[2]);
                fwrite(STDOUT, $got === null ? 'NULL' : 'CREDENTIAL');
            } catch (RuntimeException $e) {
                fwrite(STDOUT, 'RUNTIME '.$e::class);
            } catch (Throwable $e) {
                fwrite(STDOUT, 'ESCAPED '.$e::class);
            }
            PHP;

        $process = new Process(
            [PHP_BINARY, '-d', 'ffi.enable='.$ffiEnable, '-r', $code, \dirname(__DIR__, 2), 'https://ffi-off-'.bin2hex(random_bytes(4)).'.example.test'],
            timeout: WindowsCredentialStore::TIMEOUT_SECONDS * 2,
        );

        $process->run();

        return [$process->getExitCode(), trim($process->getOutput())];
    };

    // **Control first.** A child with FFI left enabled must report the store available and answer
    // NULL for a key nothing is filed under. Without it, the assertion below could be satisfied by
    // a child that failed for some unrelated reason -- a missing autoloader, a syntax error -- and
    // the two are indistinguishable from the outcome alone.
    [$healthyExit, $healthy] = $child('1');

    expect($healthyExit)->toBe(0)
        ->and($healthy)->toBe('available=true NULL');

    // The same call with the extension refused. `available()` must say false rather than raising,
    // and reaching past it must produce the one exception type every caller already handles.
    [, $disabled] = $child('0');

    expect($disabled)->toStartWith('available=false ')
        ->and($disabled)->toContain('RUNTIME '.CredentialStoreFailed::class)
        ->and($disabled)->not->toContain('ESCAPED');
})->skip(requiresFfiCredentialManager(...), 'FFI cannot reach Credential Manager on this machine.');

it('binds advapi32 and kernel32 by a name Windows resolves from KnownDLLs', function (): void {
    // Bare names rather than absolute paths, deliberately. Both libraries are KnownDLLs, which
    // Windows resolves from the `\KnownDlls` section object without consulting the application
    // directory, the working directory, or `PATH` -- so the planting vector that made the
    // PowerShell store name its interpreter absolutely does not exist here, and unlike an absolute
    // path a bare name still works on a Windows installed somewhere other than `C:\Windows`.
    $advapi = file_get_contents(\dirname(__DIR__, 2).'/app/Support/Credentials/Advapi32.php');
    $kernel = file_get_contents(\dirname(__DIR__, 2).'/app/Support/Credentials/Kernel32.php');

    expect($advapi)->toContain("'advapi32.dll'")
        ->and($kernel)->toContain("'kernel32.dll'")
        // A drive-qualified path would mean the store had moved to naming the library absolutely,
        // which is the change this test exists to notice.
        ->and($advapi)->not->toContain('C:\\Windows\\System32\\advapi32.dll')
        ->and($kernel)->not->toContain('C:\\Windows\\System32\\kernel32.dll');
});
