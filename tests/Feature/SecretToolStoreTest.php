<?php

declare(strict_types=1);

/**
 * The Secret Service store, and the property #40 exists to pin: two credential keys that differ
 * only in case must not collapse onto one entry.
 *
 * **The same two directions `KeychainStoreTest` covers, for the same reason.** The read side is
 * whether `get()` answers for the wrong casing. The write side is whether `put()` -- whose
 * `secret-tool store` REPLACES an item with matching attributes -- overwrites an entry whose key
 * differs only in case, which destroys another fleet's credential while `put()`'s read-back, reading
 * the key it just wrote, sees nothing wrong. Measured for #40 on Ubuntu 24.04.5, libsecret-tools
 * 0.21.4 and gnome-keyring 46.1: case-sensitive on both sides, so no digest is needed here.
 *
 * **Nothing in this repository's CI installs a Secret Service**, so on a runner these skip. They run
 * on a Linux machine with `secret-tool` and an unlocked keyring, and they write to it -- which is
 * why every key carries a nonce and `afterEach` removes exactly what was written.
 *
 * @command  vendor/bin/pest --compact tests/Feature/SecretToolStoreTest.php
 */

use App\Support\Credentials\Credential;
use App\Support\Credentials\SecretToolStore;

/**
 * Whether this machine cannot reach the Secret Service, which is what every test here skips on.
 *
 * Memoized, because it looks for an executable and every test asks.
 */
function requiresSecretService(): bool
{
    static $available = null;

    $available ??= new SecretToolStore()->available();

    return ! $available;
}

beforeEach(function (): void {
    $this->store = new SecretToolStore;

    // Plain ASCII differing only in case, so neither the URL shape nor the separator is what
    // decides the result
    $nonce = bin2hex(random_bytes(6));

    $this->upper = 'https://FleetA-'.$nonce.'.example.test|claude';
    $this->lower = 'https://fleeta-'.$nonce.'.example.test|claude';
    $this->absent = 'https://absent-'.$nonce.'.example.test|claude';
});

afterEach(function (): void {
    if (requiresSecretService()) {
        return;
    }

    foreach ([$this->upper, $this->lower] as $key) {
        $this->store->forget($key);

        // Verified rather than trusted, because these tests write to a real keyring
        expect($this->store->get($key))->toBeNull();
    }
});

it('returns null for a key it holds nothing for', function (): void {
    expect($this->store->get($this->absent))->toBeNull();
})->skip(requiresSecretService(...), 'The Secret Service is not reachable on this machine.');

it('does not answer for a key that differs only in case', function (): void {
    $this->store->put($this->upper, new Credential('rcouncil_1|UPPER'));

    // The positive control: the store holds it under its own casing
    expect($this->store->get($this->upper)?->equals(new Credential('rcouncil_1|UPPER')))->toBeTrue()
        ->and($this->store->get($this->lower))->toBeNull();
})->skip(requiresSecretService(...), 'The Secret Service is not reachable on this machine.');

it('keeps two keys that differ only in case as two entries, rather than overwriting one', function (): void {
    $this->store->put($this->upper, new Credential('rcouncil_1|UPPER'));
    $this->store->put($this->lower, new Credential('rcouncil_1|lower'));

    expect($this->store->get($this->upper)?->equals(new Credential('rcouncil_1|UPPER')))->toBeTrue()
        ->and($this->store->get($this->lower)?->equals(new Credential('rcouncil_1|lower')))->toBeTrue();
})->skip(requiresSecretService(...), 'The Secret Service is not reachable on this machine.');
