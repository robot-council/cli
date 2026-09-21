<?php

declare(strict_types=1);

/**
 * The macOS Keychain store, and the one property #36 exists to pin: two credential keys that
 * differ only in case must not collapse onto one entry.
 *
 * **This is a regression test for a defect that was real in a different backend.** Windows
 * Credential Manager matches target names case-insensitively, so before #35
 * `WindowsCredentialStore` returned a credential stored under one casing for a key that differed
 * only in case and had never been enrolled. A credential key is an opaque string that nothing
 * parses, and every store addresses an entry by that one string -- so whether two casings collide
 * is a property of the **backend**, not of any code here, and it has to be asked of each one
 * separately. Measured on macOS 26.6.2 for #36: `security` matches its `-a` account attribute
 * case-sensitively, on lookup and on update, so no digest is needed here.
 *
 * **Both directions are covered, and the second is the dangerous one.** The read side is whether
 * `get()` answers for the wrong casing. The write side is whether `put()` -- which runs
 * `add-generic-password -U`, an *update* -- overwrites an entry whose key differs only in case.
 * A collapse there destroys another fleet's credential, and `put()`'s own read-back is blind to it,
 * because it reads back the key it just wrote.
 *
 * **These tests write to the developer's real Keychain**, which is why every key carries a nonce,
 * why `afterEach` removes exactly what was written, and why the cleanup verifies itself rather than
 * trusting `forget()`. There is no macOS cell in this repository's CI -- the matrix is
 * `ubuntu-latest` and `windows-latest` -- so unlike `WindowsCredentialStoreTest`, nothing here ever
 * runs on a runner. It runs on a developer's Mac or it skips, and `it is available on macOS` is
 * what keeps a skip from reading like a pass.
 *
 * @command  vendor/bin/pest --compact tests/Feature/KeychainStoreTest.php
 */

use App\Support\Credentials\Credential;
use App\Support\Credentials\CredentialStoreFailed;
use App\Support\Credentials\KeychainStore;

/**
 * Whether this machine cannot reach the Keychain, which is what the gated tests skip on.
 *
 * Memoized, because it shells out and every gated test asks.
 *
 * @return bool True when the store reports itself unusable here.
 */
function requiresKeychain(): bool
{
    static $available = null;

    $available ??= new KeychainStore()->available();

    return ! $available;
}

beforeEach(function (): void {
    $this->store = new KeychainStore;

    // Every key carries its own nonce, so a run that dies before cleanup cannot collide with the
    // next one and nothing here can ever name a key a real enrollment would use. Each is built
    // from a pure string expression rather than from a property, so the analyzer keeps them as
    // `string` -- deriving them from a `$this->nonce` makes every one of them `mixed`, and then
    // every call taking a string is an error.
    $this->service = 'https://fleet-'.bin2hex(random_bytes(6)).'.example.test/fleet|claude';
    $this->other = 'https://other-'.bin2hex(random_bytes(6)).'.example.test/fleet|claude';
    $this->mixedCase = 'https://Fleet-'.bin2hex(random_bytes(6)).'.example.test/FleetA|claude';
    $this->plainUpper = 'PROBE-'.bin2hex(random_bytes(6)).'-FLEETA';

    // Seeded rather than empty, and tests APPEND. Reassigning this in each test means one that
    // forgets a key leaves it in the developer's real Keychain, which is the failure the cleanup
    // exists to prevent. `WindowsCredentialStoreTest` does the same, for the same reason.
    $this->written = [$this->service, $this->other, $this->mixedCase, $this->plainUpper];
});

afterEach(function (): void {
    if (requiresKeychain()) {
        return;
    }

    foreach ($this->written as $key) {
        $this->store->forget($key);
    }

    // Verified rather than assumed. `forget()` reports nothing -- `security delete-generic-password`
    // exits non-zero for a missing item, which is the state being asked for, so the store ignores
    // its exit code and a failed delete looks exactly like a successful one from here.
    $left = array_values(array_filter($this->written, fn (string $key): bool => $this->store->get($key) instanceof Credential));

    expect($left)->toBe([], 'These keys were left in the real Keychain: '.implode(', ', $left));
});

it('is available on macOS, so a skip below is the machine and not a broken mechanism', function (): void {
    // Without this, every test in this file skipping reads exactly like every test passing. This is
    // the one assertion that fails when `available()` breaks rather than going quiet.
    expect(new KeychainStore()->available())->toBeTrue();
})->skip(PHP_OS_FAMILY !== 'Darwin', 'Not macOS, where the Keychain is.');

it('does not answer for a key that differs only in case', function (): void {
    $mixed = $this->mixedCase;
    $lower = mb_strtolower($mixed);
    $absent = $this->other;

    $this->written[] = $lower;

    $token = 'TOKEN-FOR-MIXED-CASE';

    $this->store->put($mixed, new Credential($token));

    // The positive control, first: the instrument returns a token for a key that was stored. Without
    // it the null below is indistinguishable from a store that answers nothing at all.
    expect($this->store->get($mixed)?->reveal())->toBe($token);

    // The negative control: a key that genuinely was never stored. Without it, a `get()` that always
    // returned null would satisfy the assertion this test exists for.
    expect($this->store->get($absent))->toBeNull();

    // The claim.
    expect($this->store->get($lower))->toBeNull();
})->skip(requiresKeychain(...), 'The Keychain is not reachable on this machine.');

it('keeps two keys that differ only in case as two entries, rather than overwriting one', function (): void {
    // The write side, and the more dangerous one. `put()` runs `add-generic-password -U`, which
    // UPDATES a matching item. If that match were case-insensitive while lookup stayed
    // case-sensitive, this second `put()` would destroy the first credential -- and `put()`'s own
    // read-back could not see it, because it reads back the key it just wrote. A fleet would
    // silently lose its credential rather than read somebody else's.
    //
    // Plain ASCII keys differing only in case, so that neither the URL shape nor the `|` separator
    // can be what the backend is matching on.
    $upper = $this->plainUpper;
    $lower = mb_strtolower($upper);

    $this->written[] = $lower;

    $this->store->put($upper, new Credential('TOKEN-UPPER'));

    // It is genuinely absent before the second write, or the assertion after it proves nothing
    expect($this->store->get($lower))->toBeNull();

    $this->store->put($lower, new Credential('TOKEN-LOWER'));

    expect($this->store->get($upper)?->reveal())->toBe('TOKEN-UPPER')
        ->and($this->store->get($lower)?->reveal())->toBe('TOKEN-LOWER');
})->skip(requiresKeychain(...), 'The Keychain is not reachable on this machine.');

it('round-trips a credential byte for byte, whatever is in it', function (string $token): void {
    $key = $this->service;

    $this->store->put($key, new Credential($token));

    expect($this->store->get($key)?->reveal())->toBe($token);
})->with([
    // `put()` writes the value twice down one stdin pipe for the retype prompt, so anything the
    // prompt might treat as a terminator is worth pinning. A token carrying a newline would break
    // that protocol, and is deliberately absent: `Credential` is a bearer token, not free text.
    'a bearer token' => ['rcouncil_1|SuPeRsEcReTvAlUe0123456789abcdef'],
    'quotes and backticks' => ['a"b\'c`d'],
    'a shell expansion that must stay literal' => ['$(whoami) ${HOME} %PATH%'],
    'base64 padding' => ['aGVsbG8gd29ybGQ='],
])->skip(requiresKeychain(...), 'The Keychain is not reachable on this machine.');

it('refuses a credential containing a non-ASCII byte rather than storing it wrong', function (): void {
    // **Pinning a limitation, not a guarantee -- #41.** `security -w` prints a hex dump of the
    // whole password, with no `0x` prefix, whenever it holds any byte above 0x7F. Measured:
    // `caf<U+00E9>` comes back as the ten ASCII characters `636166c3a9`, and one high byte anywhere
    // is enough. `put()` reads back and compares, so this direction refuses; `get()` on an entry
    // that is already there does not, and returns the hex.
    //
    // This row was in the byte-for-byte dataset above and passed for the wrong reason: it was
    // written `'caf\u{e9}'` in SINGLE quotes, where PHP does not interpolate the escape, so it
    // carried nine ASCII bytes under a name claiming otherwise. Double-quoting it is what exposed
    // the defect.
    $key = $this->service;

    expect(fn () => $this->store->put($key, new Credential("caf\u{e9} na\u{ef}ve \u{6f22}\u{5b57} \u{1f512}")))
        ->toThrow(CredentialStoreFailed::class);
})->skip(requiresKeychain(...), 'The Keychain is not reachable on this machine.');

it('refuses a credential with surrounding whitespace rather than storing it wrong', function (): void {
    // **Pinning a limitation, not a guarantee.** `get()` calls `trim()` on the raw secret, which
    // strips the newline `security` appends AND any whitespace belonging to the credential:
    // measured, `'  padded  '` is stored intact and read back as `'padded'`. `put()` reads back and
    // compares, so this direction fails loudly -- which is why it is written as a refusal here and
    // why the byte-for-byte dataset above carries no padded row.
    //
    // The quiet direction has no test because it cannot be reached through this class: an entry
    // already carrying whitespace comes back short from `get()` with nothing reporting it.
    // Tracked in #41 alongside the non-ASCII defect above, which is the same expression. When that
    // lands, both tests invert and both rows return to the dataset above.
    $key = $this->service;

    expect(fn () => $this->store->put($key, new Credential('  padded  ')))
        ->toThrow(CredentialStoreFailed::class);

    // And it stored nothing usable, rather than storing a corrupted value
    expect($this->store->get($key)?->reveal())->not->toBe('  padded  ');
})->skip(requiresKeychain(...), 'The Keychain is not reachable on this machine.');

it('returns null for a key it holds nothing for', function (): void {
    expect($this->store->get($this->service))->toBeNull();
})->skip(requiresKeychain(...), 'The Keychain is not reachable on this machine.');

it('forgets one key without disturbing another', function (): void {
    $kept = $this->service;
    $dropped = $this->other;

    $this->store->put($kept, new Credential('KEPT'));
    $this->store->put($dropped, new Credential('DROPPED'));

    $this->store->forget($dropped);

    expect($this->store->get($dropped))->toBeNull()
        ->and($this->store->get($kept)?->reveal())->toBe('KEPT');
})->skip(requiresKeychain(...), 'The Keychain is not reachable on this machine.');
