<?php

declare(strict_types=1);

/**
 * Windows Credential Manager, reached through `CredWriteW`.
 *
 * Split deliberately in two. The **structural** tests run everywhere and guard the properties that
 * would be lost by a plausible simplification -- the credential arriving on stdin rather than in an
 * argument, and the service key being carried rather than parsed. The **round-trip** tests need a
 * machine with a working Credential Manager and are gated on the store saying so, in the same way
 * this suite has never written to a real Keychain or Secret Service.
 *
 * What is NOT here, and is not pretended to be: proof that the token never reaches a process's
 * argv. A watcher polling `Win32_Process` was measured on 2026-09-21 to miss a sub-second process
 * entirely -- a positive control of `cmdkey /pass:<sentinel>` returned zero hits across 70 sweeps
 * -- so an in-suite version of that check would go green whether or not the property held. It is
 * verified instead by a deterministic experiment recorded on the pull request, where both processes
 * are held open and read directly.
 *
 * @command  vendor/bin/pest --compact tests/Feature/WindowsCredentialStoreTest.php
 */

use App\Support\Credentials\Credential;
use App\Support\Credentials\CredentialStoreFailed;
use App\Support\Credentials\WindowsCredentialStore;

const WINDOWS_TOKEN = 'rcouncil_1|SuPeRsEcReTvAlUe0123456789abcdef';

/**
 * A private constant of the store, read out of the class rather than copied into the test.
 *
 * Reflection rather than reading the source file, because the source file is the wrong instrument
 * here: the class docblock discusses `cmdkey` at length to explain why it is not used, so a scan of
 * the file could never assert that the script does not call it.
 *
 * @param  string  $name  The constant to read.
 * @return string Its value.
 */
function constantOf(string $name): string
{
    $reflection = new ReflectionClass(WindowsCredentialStore::class);
    $value = $reflection->getConstant($name);

    if (! is_string($value)) {
        throw new RuntimeException(sprintf('`WindowsCredentialStore::%s` is not a string.', $name));
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

beforeEach(function (): void {
    $this->store = new WindowsCredentialStore;
    $this->service = 'https://fleet-'.bin2hex(random_bytes(6)).'.example.test';
    $this->other = 'https://other-'.bin2hex(random_bytes(6)).'.example.test';
});

afterEach(function (): void {
    // Credential Manager is the developer's real one. Anything this suite wrote comes back out,
    // whether the test passed or not. No guard on the properties being set: PHPUnit skips
    // `tearDown` when `setUp` throws, so reaching here means `beforeEach` finished.
    if (requiresCredentialManager()) {
        return;
    }

    $this->store->forget($this->service);
    $this->store->forget($this->other);
});

it('is unavailable off Windows, so a Mac or a Linux box never tries it', function (): void {
    $store = new WindowsCredentialStore;

    expect($store->available())->toBeFalse();
})->skipOnWindows();

it('takes the credential on stdin and never in an argument', function (): void {
    // The simplification this guards against is `cmdkey /generic:… /pass:<token>`, which is shorter
    // than everything in that constant and puts the token where any process on the machine can read
    // it. If somebody reaches for it, this goes red.
    expect(constantOf('SCRIPT'))->toContain('OpenStandardInput')
        ->and(constantOf('SCRIPT'))->not->toContain('cmdkey');
});

it('reads its target from the environment rather than building it into the script', function (): void {
    // A service key is an opaque string. Interpolating one into the script would make a URL
    // carrying a quote into arbitrary PowerShell, and an environment variable has no quoting to
    // break out of.
    expect(constantOf('SCRIPT'))->toContain('$env:ROBOT_COUNCIL_TARGET')
        ->and(constantOf('SCRIPT'))->toContain('$env:ROBOT_COUNCIL_OPERATION');
});

it('carries the service key without parsing it', function (): void {
    $store = new WindowsCredentialStore;

    // Every one of these is a legal key as far as this store is concerned, and each would break
    // something that tried to split, quote, or normalize it.
    foreach ([':leading-colon', 'https://x.test/a b', 'with"quote', "with\nnewline", ''] as $service) {
        expect($store->target($service))->toBe(WindowsCredentialStore::TARGET_PREFIX.$service);
    }
});

it('probes a target no service key can ever produce', function (): void {
    // `available()` reads this target and requires "no such credential". If a real credential could
    // sit there -- which it could, for a service key of `:availability-probe`, back when the probe
    // target was the prefix plus a colon -- then a machine that had enrolled against that key would
    // have its own token read as a broken mechanism, and silently fall through to the file store.
    expect(constantOf('PROBE_TARGET'))->not->toStartWith(WindowsCredentialStore::TARGET_PREFIX);
});

it('names Credential Manager, so a developer can go and look', function (): void {
    $store = new WindowsCredentialStore;

    expect($store->describe())
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

it('keeps two services apart rather than overwriting one with the other', function (): void {
    $this->store->put($this->service, new Credential(WINDOWS_TOKEN));
    $this->store->put($this->other, new Credential('a-different-credential'));

    // A machine enrolled against two deployments holds two credentials, matching the other stores.
    // Keyed on anything but the service, the second enrollment would log the machine out of the
    // first and say nothing.
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

    $this->store->put($hostile, new Credential(WINDOWS_TOKEN));

    expect($this->store->get($hostile)?->reveal())->toBe(WINDOWS_TOKEN);

    $this->store->forget($hostile);
})->skip(requiresCredentialManager(...), 'Credential Manager is not reachable on this machine.');

it('refuses a credential longer than Credential Manager accepts, saying so', function (): void {
    // The ceiling is `CRED_MAX_CREDENTIAL_BLOB_SIZE`, and it was measured rather than read: with
    // the guard bypassed on 2026-09-21, 2560 bytes round-tripped and 2561 failed inside
    // `CredWriteW`. Without the guard the caller gets "could not be stored" from the read-back,
    // which is true and says nothing about why.
    expect(fn () => $this->store->put($this->service, new Credential(str_repeat('x', 2561))))
        ->toThrow(CredentialStoreFailed::class, '2560 bytes');
})->skip(requiresCredentialManager(...), 'Credential Manager is not reachable on this machine.');

it('stores a credential exactly at the ceiling', function (): void {
    $token = str_repeat('x', 2560);

    // The other side of the boundary, so the guard is pinned to where the API actually stops rather
    // than to a round number somebody chose.
    $this->store->put($this->service, new Credential($token));

    expect($this->store->get($this->service)?->reveal())->toBe($token);
})->skip(requiresCredentialManager(...), 'Credential Manager is not reachable on this machine.');

it('leaves no script behind in the temporary directory', function (): void {
    $before = glob(sys_get_temp_dir().'/robot-council-*.ps1') ?: [];

    $this->store->put($this->service, new Credential(WINDOWS_TOKEN));
    $this->store->get($this->service);
    $this->store->forget($this->service);

    $after = glob(sys_get_temp_dir().'/robot-council-*.ps1') ?: [];

    // The script holds no credential, so a leak here is untidy rather than dangerous -- but it runs
    // on every call, and a store that scatters a file per call would fill a temp directory.
    expect($after)->toBe($before);
})->skip(requiresCredentialManager(...), 'Credential Manager is not reachable on this machine.');
