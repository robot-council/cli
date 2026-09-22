<?php

declare(strict_types=1);

/**
 * The target name, the ceilings, and the refusal both Windows stores share.
 *
 * **Every test here runs on every platform, and that is the point.** The behavioral halves of
 * `WindowsCredentialStoreTest` and `WindowsFfiCredentialStoreTest` are gated on a reachable
 * Credential Manager, so on ubuntu and macOS -- which is most of the CI matrix -- nothing else
 * checks any of this. What this file guards is arithmetic and agreement, neither of which needs
 * Windows to be wrong.
 *
 * @command  vendor/bin/pest --compact tests/Feature/WindowsCredentialTargetTest.php
 */

use App\Support\Credentials\CredentialStoreFailed;
use App\Support\Credentials\WindowsCredentialStore;
use App\Support\Credentials\WindowsCredentialTarget;
use App\Support\Credentials\WindowsFfiCredentialStore;

it('counts a user name in UTF-16 code units, not bytes', function (string $label, string $character, int $unitsEach): void {
    // **This is the assertion that stops the obvious wrong fix.** Windows caps the user name in
    // UTF-16 code units, and `strlen()` counts UTF-8 bytes -- which agree only for ASCII. Measured
    // on 2026-09-22 against `advapi32`, every alphabet below is refused at 513 code units and
    // stored at 512, whatever that costs in bytes: 512 kanji is 1,536 bytes and stores fine.
    //
    // A `strlen()` check would refuse it, and would be wrong in the direction nobody notices --
    // turning away a key Windows was happy to take.
    $atCeiling = str_repeat($character, \intdiv(WindowsCredentialTarget::MAX_USER_NAME_UNITS, $unitsEach));

    expect(WindowsCredentialTarget::userNameUnits($atCeiling))
        ->toBe(WindowsCredentialTarget::MAX_USER_NAME_UNITS);

    // And the control that makes the line above mean something: for everything except ASCII the
    // byte count is larger, so a check written against bytes would give a different answer.
    expect(\strlen($atCeiling) === WindowsCredentialTarget::MAX_USER_NAME_UNITS)->toBe($label === 'ascii');
})->with([
    ['ascii', 'a', 1],
    ['e-acute', "\u{00e9}", 1],
    ['kanji', "\u{6771}", 1],
    ['emoji', "\u{1F600}", 2],
]);

it('pins both ceilings to the figures the Windows API actually enforces', function (): void {
    // **Every other test here derives its sizes from these constants, and that is why this one
    // exists.** A constant that drifts DOWNWARD takes its derived tests with it: set the user-name
    // ceiling to 511 and the boundary tests still pass, because they would store 511 and check that
    // 512 is refused -- refused by this code, while Windows would have stored it. Nothing would say
    // the rule had stopped matching the API.
    //
    // #49 recorded the same hole for `MAX_BLOB_BYTES`, where both boundary tests derived from the
    // constant and neither could notice it moving. These literals are the fix for it: they are the
    // measured API limits, and they are the one place a drift has to show up.
    //
    // Measured against `advapi32` on 2026-09-21 and 2026-09-22 respectively.
    expect(WindowsCredentialTarget::MAX_BLOB_BYTES)->toBe(2560)
        ->and(WindowsCredentialTarget::MAX_USER_NAME_UNITS)->toBe(512);
});

it('accepts a service key at the ceiling and refuses one past it', function (): void {
    // Both sides of the boundary, with the sizes taken from the constant so the rule and its test
    // cannot drift apart.
    $ceiling = WindowsCredentialTarget::MAX_USER_NAME_UNITS;

    expect(fn () => WindowsCredentialTarget::assertStorable(str_repeat('a', $ceiling)))
        ->not->toThrow(CredentialStoreFailed::class);

    $oneOver = fn () => WindowsCredentialTarget::assertStorable(str_repeat('a', $ceiling + 1));

    // The message names the limit, which is the whole reason this refusal exists: `CredWriteW`
    // reports an over-long user name as Windows error 1734, and the PowerShell path cannot even
    // surface that.
    expect($oneOver)->toThrow(CredentialStoreFailed::class, (string) $ceiling);
});

it('refuses a non-ASCII key by its code units rather than its bytes', function (): void {
    // The case a `strlen()` check gets backwards. 512 kanji is 1,536 bytes and Windows stores it;
    // 513 kanji is 1,539 bytes and Windows refuses it. A byte-based rule would refuse both.
    $ceiling = WindowsCredentialTarget::MAX_USER_NAME_UNITS;
    $storable = str_repeat("\u{6771}", $ceiling);
    $tooLong = str_repeat("\u{6771}", $ceiling + 1);

    expect(\strlen($storable))->toBeGreaterThan($ceiling)
        ->and(fn () => WindowsCredentialTarget::assertStorable($storable))->not->toThrow(CredentialStoreFailed::class)
        ->and(fn () => WindowsCredentialTarget::assertStorable($tooLong))->toThrow(CredentialStoreFailed::class);
});

it('gives both Windows stores the same target for the same key', function (): void {
    // The drift this class exists to prevent, asserted where no Windows machine is needed. A
    // machine that gained or lost FFI would otherwise stop finding what it had already stored, and
    // report it as "not enrolled" -- a target nobody wrote to and a machine never enrolled are the
    // same answer, so nothing would say so.
    $powershell = new WindowsCredentialStore;
    $ffi = new WindowsFfiCredentialStore;

    foreach ([':leading-colon', 'https://x.test/a b', 'with"quote', "with\nnewline", "caf\u{00e9}", ''] as $service) {
        expect($ffi->target($service))->toBe($powershell->target($service))
            ->and($ffi->target($service))->toBe(WindowsCredentialTarget::for($service));
    }
});

it('keeps the probe prefix outside every target a key can produce', function (): void {
    // A probe reads a target under this prefix and requires "no such credential". If a real
    // credential could sit there, a machine enrolled against that key would have its own token read
    // as a broken mechanism.
    expect(WindowsCredentialTarget::PROBE_PREFIX)->not->toStartWith(WindowsCredentialTarget::PREFIX);
});
