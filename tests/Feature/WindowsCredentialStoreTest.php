<?php

declare(strict_types=1);

/**
 * Windows Credential Manager, reached through `CredWriteW`.
 *
 * Split deliberately in two. The **structural** tests run everywhere and guard properties that a
 * plausible simplification would take away -- the credential arriving on stdin rather than in an
 * argument, the token staying out of exception traces, and the key being carried rather than
 * parsed. The **round-trip** tests need a machine with a working Credential Manager and are gated
 * on the store saying so, in the same way this suite has never written to a real Keychain or
 * Secret Service.
 *
 * What is NOT here, and is not pretended to be: proof that the token never reaches a process's
 * argv. A watcher polling `Win32_Process` was measured on 2026-09-21 to miss a sub-second process
 * entirely -- a positive control of `cmdkey /pass:<sentinel>` returned zero hits across 70 sweeps
 * -- so an in-suite version of that check would go green whether or not the property held. It is
 * verified instead by a deterministic experiment recorded on the pull request, where both
 * processes are held open and their command lines read directly.
 *
 * @command  vendor/bin/pest --compact tests/Feature/WindowsCredentialStoreTest.php
 */

use App\Support\Credentials\Credential;
use App\Support\Credentials\CredentialStoreFailed;
use App\Support\Credentials\WindowsCredentialStore;

const WINDOWS_TOKEN = 'rcouncil_1|SuPeRsEcReTvAlUe0123456789abcdef';

/**
 * A constant of the store, read out of the class rather than copied into the test.
 *
 * Reflection rather than reading the source file, because the source file is the wrong instrument
 * here: the class docblock discusses `cmdkey` at length to explain why it is not used, so a scan
 * of the file could never assert that the script does not call it.
 *
 * @param  string  $name  The constant to read.
 * @return string Its value.
 */
function constantOf(string $name): string
{
    $value = new ReflectionClass(WindowsCredentialStore::class)->getConstant($name);

    if (! is_string($value)) {
        throw new RuntimeException(sprintf('`WindowsCredentialStore::%s` is not a string.', $name));
    }

    return $value;
}

/**
 * An integer constant of the store, narrowed rather than cast.
 *
 * @param  string  $name  The constant to read.
 * @return int Its value.
 */
function intConstantOf(string $name): int
{
    $value = new ReflectionClass(WindowsCredentialStore::class)->getConstant($name);

    if (! is_int($value)) {
        throw new RuntimeException(sprintf('`WindowsCredentialStore::%s` is not an int.', $name));
    }

    return $value;
}

/**
 * Skip unless this machine can actually reach Credential Manager.
 *
 * Memoized across the file. `available()` costs a `powershell.exe` start and an `Add-Type` compile,
 * and asking it once per test added about fourteen seconds to the run for one unchanging answer.
 */
function requiresCredentialManager(): bool
{
    static $available = null;

    $available ??= new WindowsCredentialStore()->available();

    return ! $available;
}

/**
 * Whether this machine has the interpreter the store needs.
 *
 * Deliberately not `PHP_OS_FAMILY === 'Windows'`: the point of the test that uses it is to assert
 * the store IS available wherever it could be, so its own condition must not be the store's.
 */
function hasWindowsPowerShell(): bool
{
    $root = getenv('SystemRoot');

    if (! is_string($root) || $root === '') {
        $root = 'C:\Windows';
    }

    return PHP_OS_FAMILY === 'Windows'
        && is_executable(rtrim($root, '\\/').'\System32\WindowsPowerShell\v1.0\powershell.exe');
}

beforeEach(function (): void {
    $this->store = new WindowsCredentialStore;
    $this->service = 'https://fleet-'.bin2hex(random_bytes(6)).'.example.test';
    $this->other = 'https://other-'.bin2hex(random_bytes(6)).'.example.test';

    // Every key a test writes goes in here, and `afterEach` removes exactly these. A hard-coded
    // pair used to be the cleanup, so a test that wrote anything else left it in the developer's
    // real Credential Manager -- including the hostile-key test, whose residue read
    // `robot-council:https://x.test"; Start-Process calc.exe; #`.
    $this->written = [$this->service, $this->other];
});

afterEach(function (): void {
    // Credential Manager is the developer's real one, so anything this suite wrote comes back out
    // whether the test passed or not. No guard on the properties being set: PHPUnit skips
    // `tearDown` when `setUp` throws, so reaching here means `beforeEach` finished.
    if (requiresCredentialManager()) {
        return;
    }

    foreach ($this->written as $key) {
        $this->store->forget($key);
    }
});

it('keeps the credential out of every exception trace frame', function (): void {
    // **The defect this guards against has been paid for once already.** `Credential` exists
    // because Collision printed `UserFileStore::put("https://…", "rcouncil_1|…")` to stdout, and
    // its docblock says carrying the token in an object removes it from every frame on every
    // failure path. Passing it onward as a plain `string` parameter puts it straight back, and
    // base64 is transport encoding rather than protection. PHP captures arguments into traces
    // whenever `zend.exception_ignore_args` is `0`, which is its default.
    $input = new ReflectionMethod(WindowsCredentialStore::class, 'run')->getParameters()[3];

    expect($input->getName())->toBe('input')
        ->and($input->getAttributes(SensitiveParameter::class))->not->toBeEmpty();
});

it('is unavailable where `powershell.exe` is not', function (): void {
    // Named for what it proves. The operating-system check in `probe()` is belt-and-braces: on a
    // Mac or a Linux box the Windows interpreter path does not exist either, so this stays green
    // with that check deleted. The executable check is the mechanism that actually decides.
    expect(new WindowsCredentialStore()->available())->toBeFalse();
})->skip(hasWindowsPowerShell(...), 'This machine has the interpreter, so the store should be available.');

it('is available on a machine that has the interpreter', function (): void {
    // **This is the test that fails when the whole feature silently stops working.** Every
    // round-trip test below is gated on `available()`, so a mechanism broken anywhere -- a changed
    // exit code, an environment variable the script no longer receives, a renamed operation --
    // turns the behavioral half of this file into skips, and a suite of skips reads exactly like a
    // suite of passes. Without this assertion nothing anywhere says the store should work.
    expect(new WindowsCredentialStore()->available())->toBeTrue();
})->skip(fn (): bool => ! hasWindowsPowerShell(), 'No Windows PowerShell on this machine.');

it('takes the credential on stdin and never in an argument', function (): void {
    // Scoped to the script, which is what is checked: a `cmdkey` call added elsewhere in the class
    // would not be caught here. The simplification this guards against is
    // `cmdkey /generic:… /pass:<token>`, shorter than everything in that constant and putting the
    // token where any process on the machine can read it.
    expect(constantOf('SCRIPT'))->toContain('OpenStandardInput')
        ->and(constantOf('SCRIPT'))->not->toContain('cmdkey');
});

it('reads its target from the environment rather than building it into the script', function (): void {
    // A service key is an opaque string. Interpolating one into the script would make a URL
    // carrying a quote into arbitrary PowerShell, and an environment variable has no quoting to
    // break out of.
    expect(constantOf('SCRIPT'))->toContain('$env:ROBOT_COUNCIL_TARGET')
        ->and(constantOf('SCRIPT'))->toContain('$env:ROBOT_COUNCIL_OPERATION')
        ->and(constantOf('SCRIPT'))->toContain('$env:ROBOT_COUNCIL_USERNAME');
});

it('agrees with its script about which exit code means "no such credential"', function (): void {
    // `NOT_FOUND` and the script's `exit` literal are coupled by nothing but this test. Change
    // either alone and `available()` returns false on every machine, every gated test below skips,
    // and the suite is green.
    expect(constantOf('SCRIPT'))->toContain(sprintf('exit %d', intConstantOf('NOT_FOUND')));
});

it('probes a target no service key can ever produce', function (): void {
    // `available()` reads a target under this prefix and requires "no such credential". If a real
    // credential could sit there -- which it could, for a service key of `:availability-probe`,
    // back when the probe target was `TARGET_PREFIX` plus a colon -- then a machine enrolled
    // against that key would have its own token read as a broken mechanism.
    expect(constantOf('PROBE_PREFIX'))->not->toStartWith(WindowsCredentialStore::TARGET_PREFIX);
});

it('carries the service key without parsing it', function (): void {
    $store = new WindowsCredentialStore;

    // Every one of these is a legal key as far as this store is concerned, and each would break
    // something that tried to split, quote, or normalize it.
    foreach ([':leading-colon', 'https://x.test/a b', 'with"quote', "with\nnewline", ''] as $service) {
        expect($store->target($service))->toStartWith(WindowsCredentialStore::TARGET_PREFIX.$service);
    }
});

it('gives two keys that differ only in case two different targets', function (): void {
    $store = new WindowsCredentialStore;

    // **Credential Manager matches target names case-insensitively and the key is case-sensitive
    // everywhere else.** Measured on 2026-09-21: with the bare key as the target, a credential
    // stored under `…/FleetA|claude` was returned for `…/fleeta|claude`, which was never enrolled,
    // while a key that genuinely was not stored returned null. `UserFileStore` returns null for
    // that same pair. Without distinct targets this store alone hands one fleet's bearer token to
    // another fleet whose URL differs only in case.
    // **Compared with both sides lowercased, which is the whole point.** Comparing the targets as
    // written asserts only that PHP string comparison is case-sensitive, which it is whatever this
    // method does -- that version of this test passed with the digest removed. Credential Manager
    // matches case-insensitively, so the property that matters is that the targets still differ
    // once case is taken away.
    expect(strtolower($store->target('https://x.test/FleetA|claude')))
        ->not->toBe(strtolower($store->target('https://x.test/fleeta|claude')));
});

it('names Credential Manager, so a developer can go and look', function (): void {
    expect(new WindowsCredentialStore()->describe())
        ->toContain('Credential Manager')
        ->toContain('cmdkey /list');
});

it('stores a credential and reads it back', function (): void {
    $this->store->put($this->service, new Credential(WINDOWS_TOKEN));

    expect($this->store->get($this->service)?->reveal())->toBe(WINDOWS_TOKEN);
})->skip(requiresCredentialManager(...), 'Credential Manager is not reachable on this machine.');

it('returns null for a service it holds nothing for', function (): void {
    expect($this->store->get($this->service))->toBeNull();
})->skip(requiresCredentialManager(...), 'Credential Manager is not reachable on this machine.');

it('does not answer for a key that differs only in case', function (): void {
    $mixed = 'https://case-'.bin2hex(random_bytes(4)).'.example.test/FleetA|claude';
    $lower = strtolower($mixed);

    $this->written[] = $mixed;
    $this->written[] = $lower;

    $this->store->put($mixed, new Credential(WINDOWS_TOKEN));

    // The regression test for the case-collapse above. Read under the other casing, this returned
    // the stored token before `target()` appended a case-sensitive digest.
    expect($this->store->get($mixed)?->reveal())->toBe(WINDOWS_TOKEN)
        ->and($this->store->get($lower))->toBeNull();

    // And the other direction: writing the lowercased key must not destroy the first.
    $this->store->put($lower, new Credential('a-different-credential'));

    expect($this->store->get($mixed)?->reveal())->toBe(WINDOWS_TOKEN)
        ->and($this->store->get($lower)?->reveal())->toBe('a-different-credential');
})->skip(requiresCredentialManager(...), 'Credential Manager is not reachable on this machine.');

it('keeps two services apart rather than overwriting one with the other', function (): void {
    $this->store->put($this->service, new Credential(WINDOWS_TOKEN));
    $this->store->put($this->other, new Credential('a-different-credential'));

    // A machine enrolled against two deployments holds two credentials, matching the other stores.
    // Keyed on anything but the key, the second enrollment would log the machine out of the first
    // and say nothing.
    expect($this->store->get($this->service)?->reveal())->toBe(WINDOWS_TOKEN)
        ->and($this->store->get($this->other)?->reveal())->toBe('a-different-credential');
})->skip(requiresCredentialManager(...), 'Credential Manager is not reachable on this machine.');

it('forgets one service without disturbing another', function (): void {
    $this->store->put($this->service, new Credential(WINDOWS_TOKEN));
    $this->store->put($this->other, new Credential('a-different-credential'));

    $this->store->forget($this->service);

    expect($this->store->get($this->service))->toBeNull()
        ->and($this->store->get($this->other)?->reveal())->toBe('a-different-credential');
})->skip(requiresCredentialManager(...), 'Credential Manager is not reachable on this machine.');

it('forgets a service it never held without complaining', function (): void {
    $this->store->forget($this->service);
})->skip(requiresCredentialManager(...), 'Credential Manager is not reachable on this machine.')
    ->throwsNoExceptions();

it('round-trips a credential byte for byte, whatever is in it', function (string $token): void {
    $this->store->put($this->service, new Credential($token));

    // A credential is opaque. Trimming it, decoding it through a console code page, or letting
    // PowerShell interpolate it would each corrupt one of these and leave the rest working.
    expect($this->store->get($this->service)?->reveal())->toBe($token);
})->with([
    'a quote and a backtick' => ["tok'en\"with`quotes"],
    'a PowerShell subexpression' => ['tok$en$(Get-Process)${x}'],
    'multibyte' => ["t\u{00F6}k\u{00E8}n-\u{65E5}\u{672C}\u{8A9E}"],
    'newlines' => ["line1\nline2\r\nline3"],
    'percent signs' => ['a%PATH%b%%c'],
    'leading and trailing spaces' => ['   token with spaces   '],
    'base64 padding' => ['aGVsbG8=padding=='],
])->skip(requiresCredentialManager(...), 'Credential Manager is not reachable on this machine.');

it('stores a service key that would be an injection if anything parsed it', function (): void {
    $hostile = 'https://x.test"; Start-Process calc.exe; #';

    $this->written[] = $hostile;

    $this->store->put($hostile, new Credential(WINDOWS_TOKEN));

    expect($this->store->get($hostile)?->reveal())->toBe(WINDOWS_TOKEN);
})->skip(requiresCredentialManager(...), 'Credential Manager is not reachable on this machine.');

it('refuses a credential longer than Credential Manager accepts, saying so', function (): void {
    $ceiling = intConstantOf('MAX_BLOB_BYTES');

    // **Deliberately not gated, while its neighbour below is.** The guard is the first statement
    // in `put()` and nothing above it touches the store -- `put()` never calls `available()` -- so
    // this is pure PHP and runs on every platform. Gating it put the guard's only test behind a
    // working Credential Manager, which meant neither ubuntu cell exercised it.
    expect(fn () => $this->store->put($this->service, new Credential(str_repeat('x', $ceiling + 1))))
        ->toThrow(CredentialStoreFailed::class, (string) $ceiling);
});

it('stores a credential exactly at the ceiling', function (): void {
    $token = str_repeat('x', intConstantOf('MAX_BLOB_BYTES'));

    // The other side of the same boundary, and this one does need the API: `CRED_MAX_CREDENTIAL_
    // BLOB_SIZE` was measured rather than read, with the guard bypassed on 2026-09-21, and 2560
    // round-tripped where 2561 failed inside `CredWriteW`. One side of the boundary is a guard,
    // the other is the API, which is why only this one is gated.
    $this->store->put($this->service, new Credential($token));

    expect($this->store->get($this->service)?->reveal())->toBe($token);
})->skip(requiresCredentialManager(...), 'Credential Manager is not reachable on this machine.');

it('leaves no script behind in the temporary directory', function (): void {
    $pattern = sys_get_temp_dir().'/'.WindowsCredentialStore::SCRIPT_PREFIX.'*.ps1';

    // **Positive control first.** Two empty sets compare equal, so without showing this glob can
    // match something the store would have written, "nothing left behind" and "this glob can never
    // match" are the same result. The prefix comes from the class rather than a copy of the
    // literal, so renaming it in the store makes this test red instead of vacuous.
    $planted = sys_get_temp_dir().'/'.WindowsCredentialStore::SCRIPT_PREFIX.bin2hex(random_bytes(8)).'.ps1';
    touch($planted);

    expect(glob($pattern) ?: [])->toContain($planted);

    unlink($planted);

    $before = glob($pattern) ?: [];

    $this->store->put($this->service, new Credential(WINDOWS_TOKEN));
    $this->store->get($this->service);
    $this->store->forget($this->service);

    // The script holds no credential, so a leak here is untidy rather than dangerous -- but it is
    // written on every call, and a store that scattered a file per call would fill a temp
    // directory.
    expect(glob($pattern) ?: [])->toBe($before);
})->skip(requiresCredentialManager(...), 'Credential Manager is not reachable on this machine.');
