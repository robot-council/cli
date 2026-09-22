<?php

declare(strict_types=1);

/**
 * Reading several credentials in one call, and the guarantees that survive the batching.
 *
 * **What this buys is one process, not one query.** `storedAmong()` asks about every known harness
 * while the command line is already refusing, and on a store whose reads are subprocesses that is
 * fifteen of them -- measured for #31 on Windows at 11,656ms serial against 974ms batched. The
 * capability is optional, so only a store that has something to win declares it.
 *
 * **The batch path has no implementation in the application yet**, by design: #90 adds it to
 * `WindowsCredentialStore`. So `BatchingProbeStore` is not a convenience here, it is the only thing
 * that exercises the branch at all, and without it #90 would be the first code ever to run it.
 *
 * @command  vendor/bin/pest --compact tests/Feature/ManyCredentialsTest.php
 */

use App\Support\Credentials\Credential;
use App\Support\Credentials\CredentialKey;
use App\Support\Credentials\Credentials;
use Tests\Fixtures\BatchingProbeStore;
use Tests\Fixtures\ProbeStore;

const MANY_FLEET = 'https://many.example.test';

/**
 * Put a credential for one harness into a probe store.
 *
 * @param  ProbeStore  $store  The store to seed.
 * @param  string  $harness  The harness to enroll.
 */
function enroll(ProbeStore $store, string $harness): void
{
    $store->put(CredentialKey::for(MANY_FLEET, $harness), new Credential('token-'.$harness));
}

it('asks a batching store once, rather than once per harness', function (): void {
    $inner = new ProbeStore;
    enroll($inner, 'claude');
    enroll($inner, 'cursor');

    $store = new BatchingProbeStore($inner);

    $found = new Credentials([$store])->storedAmong(MANY_FLEET, ['claude', 'codex', 'cursor']);

    expect($found)->toBe(['claude', 'cursor'])
        // One call, carrying all three keys. This is the whole point of the ticket.
        ->and($store->batches)->toHaveCount(1)
        ->and($store->batches[0])->toHaveCount(3)
        // **And no per-key reads happened**, which is what "one process" actually means. Counting
        // only the batches would pass for a store that batched AND then looped.
        ->and($inner->reads)->toBeEmpty();
});

it('asks a store without the capability once per harness, exactly as before', function (): void {
    $store = new ProbeStore;
    enroll($store, 'claude');

    $found = new Credentials([$store])->storedAmong(MANY_FLEET, ['claude', 'codex']);

    expect($found)->toBe(['claude'])
        ->and($store->reads)->toHaveCount(2);
});

it('answers in the order asked for, whatever order the store replies in', function (): void {
    // **A batching store decides its own output order**, and `remedies()` prints this list to a
    // person. Without this the result would follow the store's map rather than the argument.
    $inner = new ProbeStore;
    enroll($inner, 'claude');
    enroll($inner, 'cursor');

    $store = new BatchingProbeStore($inner, reversed: true);

    $found = new Credentials([$store])->storedAmong(MANY_FLEET, ['claude', 'codex', 'cursor']);

    expect($found)->toBe(['claude', 'cursor']);
});

it('reports a batching store and a looping one identically, given the same credentials', function (): void {
    // The agreement criterion. Two stores holding the same thing must answer the same, or #90
    // changes behavior on Windows while every test stays green.
    $harnesses = ['claude', 'codex', 'cursor', 'copilot'];

    $looping = new ProbeStore;
    enroll($looping, 'claude');
    enroll($looping, 'cursor');

    $inner = new ProbeStore;
    enroll($inner, 'claude');
    enroll($inner, 'cursor');

    expect(new Credentials([new BatchingProbeStore($inner)])->storedAmong(MANY_FLEET, $harnesses))
        ->toBe(new Credentials([$looping])->storedAmong(MANY_FLEET, $harnesses));
});

it('keeps the harnesses it could read when one key is unreadable', function (): void {
    // **The lower bound, on the looping path.** One broken key costs that key and no other.
    $store = new ProbeStore;
    enroll($store, 'claude');
    enroll($store, 'cursor');
    $store->unreadable = [CredentialKey::for(MANY_FLEET, 'cursor')];

    $found = new Credentials([$store])->storedAmong(MANY_FLEET, ['claude', 'cursor']);

    expect($found)->toBe(['claude']);
});

it('keeps the harnesses it could read when a BATCHING store cannot read one key', function (): void {
    // The same property through the batch path, which is where it could quietly be lost: a batch
    // that raised for one key would take every harness with it. The interface requires omission
    // instead, and this is the test that holds #90 to it.
    $inner = new ProbeStore;
    enroll($inner, 'claude');
    enroll($inner, 'cursor');
    $inner->unreadable = [CredentialKey::for(MANY_FLEET, 'cursor')];

    $found = new Credentials([new BatchingProbeStore($inner)])->storedAmong(MANY_FLEET, ['claude', 'cursor']);

    expect($found)->toBe(['claude']);
});

it('answers nothing rather than aborting when a batching store cannot run at all', function (): void {
    $inner = new ProbeStore;
    enroll($inner, 'claude');

    $store = new BatchingProbeStore($inner, refuses: true);

    $found = new Credentials([$store])->storedAmong(MANY_FLEET, ['claude', 'cursor']);

    // **The control that makes the empty answer mean something.** An empty list is what a store
    // holding nothing returns too, so without evidence the call happened this assertion would pass
    // against a `storedAmong()` that never consulted the store at all.
    expect($found)->toBeEmpty()
        ->and($store->batches)->toHaveCount(1);
});

it('tells a batch that found nothing from one that never ran', function (): void {
    // #31's first prototype reported `442ms, found 0` having inserted nothing and fallen through
    // to its script's `default { exit 4 }`. The result alone cannot distinguish the two.
    $store = new BatchingProbeStore(new ProbeStore);

    $found = new Credentials([$store])->storedAmong(MANY_FLEET, ['claude', 'codex']);

    expect($found)->toBeEmpty()
        ->and($store->batches)->toBe([[
            CredentialKey::for(MANY_FLEET, 'claude'),
            CredentialKey::for(MANY_FLEET, 'codex'),
        ]]);
});

it('asks nothing at all when given no harnesses', function (): void {
    $store = new BatchingProbeStore(new ProbeStore);

    expect(new Credentials([$store])->storedAmong(MANY_FLEET, []))->toBeEmpty()
        // A batch of nothing is still a process start on the store this exists for, so the empty
        // case is worth not paying for. One call carrying an empty list would also be correct;
        // this pins which one ships, so a change is a decision rather than a drift.
        ->and($store->batches)->toBe([[]]);
});
