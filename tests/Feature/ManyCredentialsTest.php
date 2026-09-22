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
use Tests\Fixtures\PositionalBatchingStore;
use Tests\Fixtures\ProbeStore;
use Tests\Fixtures\TargetKeyedBatchingStore;

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

it("finds credentials in a store whose own addressing differs from the caller's keys", function (): void {
    // **The round trip is only a real round trip when the two key spaces differ.**
    // `BatchingProbeStore` answers with the key it was handed, so translation is a no-op there and
    // a store that skipped it would still pass. This one files under
    // `WindowsCredentialTarget::for()`, exactly as `WindowsCredentialStore` does, so the key asked
    // about and the name holding the credential are different strings by construction.
    $store = new TargetKeyedBatchingStore;
    $store->put(CredentialKey::for(MANY_FLEET, 'claude'), new Credential('token-claude'));
    $store->put(CredentialKey::for(MANY_FLEET, 'cursor'), new Credential('token-cursor'));

    $found = new Credentials([$store])->storedAmong(MANY_FLEET, ['claude', 'codex', 'cursor']);

    expect($found)->toBe(['claude', 'cursor'])
        ->and($store->batches)->toHaveCount(1);
});

it("sees nothing from a batch answered in the store's own key space, and is told nothing about it", function (): void {
    // **The failure mode the contract exists to prevent, characterized rather than described.**
    // A batch keyed by target name returns a map whose every key misses `isset()`, so an enrolled
    // machine is reported as having nothing stored and `remedies()` tells the operator to run
    // `enroll` first. The call succeeded, the answer is well-formed, and an empty result is what a
    // machine with no credentials returns too -- so nothing anywhere reports it.
    $wrong = new TargetKeyedBatchingStore(answersInStoreSpace: true);
    $right = new TargetKeyedBatchingStore;

    foreach ([$wrong, $right] as $store) {
        $store->put(CredentialKey::for(MANY_FLEET, 'claude'), new Credential('token-claude'));
        $store->put(CredentialKey::for(MANY_FLEET, 'cursor'), new Credential('token-cursor'));
    }

    expect(new Credentials([$wrong])->storedAmong(MANY_FLEET, ['claude', 'cursor']))->toBeEmpty()
        // It ran and it held the credentials, so the empty answer is the translation and nothing
        // else. Without this the assertion would pass against a store that was simply empty.
        ->and($wrong->batches)->toHaveCount(1)
        ->and($wrong->stored)->toHaveCount(2)
        // The control: one flag apart, the same fixture holding the same credentials answers.
        ->and(new Credentials([$right])->storedAmong(MANY_FLEET, ['claude', 'cursor']))->toBe(['claude', 'cursor']);
});

it('finds nothing through the looping default, and is shown to have asked', function (): void {
    // Criterion 3 asks for a finds-none case on **the default**. The finds-none test below drives
    // a batching store, so it covers the other branch; this one covers the loop, and `$reads` is
    // what separates "asked and found nothing" from "never asked".
    $store = new ProbeStore;

    expect(new Credentials([$store])->storedAmong(MANY_FLEET, ['claude', 'codex']))->toBeEmpty()
        ->and($store->reads)->toBe([
            CredentialKey::for(MANY_FLEET, 'claude'),
            CredentialKey::for(MANY_FLEET, 'codex'),
        ]);
});

it('is misled by a batch that pairs answers to keys by position, and the harnesses come back wrong', function (): void {
    // **The failure the contract's positional clause exists to prevent, characterized.**
    // `codex` holds nothing, so a batch that answers with what it found and pairs by index files
    // `cursor`'s credential under `codex`. The result is not empty, it is confidently wrong: one
    // harness reported enrolled when it is not, and one reported absent when it is.
    //
    // Neither other fixture can produce this. Both build their map keyed by the key they are
    // answering for, so no omission can shift them.
    $harnesses = ['claude', 'codex', 'cursor'];

    $positional = new PositionalBatchingStore;
    $correct = new PositionalBatchingStore(positional: false);

    foreach ([$positional, $correct] as $store) {
        $store->put(CredentialKey::for(MANY_FLEET, 'claude'), new Credential('token-claude'));
        $store->put(CredentialKey::for(MANY_FLEET, 'cursor'), new Credential('token-cursor'));
    }

    expect(new Credentials([$positional])->storedAmong(MANY_FLEET, $harnesses))
        // `cursor` is enrolled and is not listed; `codex` is not enrolled and is.
        ->toBe(['claude', 'codex'])
        ->and($positional->batches)->toHaveCount(1)
        // The control: the same fixture, the same credentials, one flag apart.
        ->and(new Credentials([$correct])->storedAmong(MANY_FLEET, $harnesses))->toBe(['claude', 'cursor']);
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
    //
    // **The unreadable key is FIRST, and that placement is the whole test.** With it last, a loop
    // that abandoned every remaining key on the first failure would answer identically to one that
    // skipped it. Measured: with the unreadable key last, changing `continue` to `break` passed
    // this file and all 227 tests in the suite.
    $store = new ProbeStore;
    enroll($store, 'codex');
    enroll($store, 'cursor');
    $store->unreadable = [CredentialKey::for(MANY_FLEET, 'claude')];

    $found = new Credentials([$store])->storedAmong(MANY_FLEET, ['claude', 'codex', 'cursor']);

    expect($found)->toBe(['codex', 'cursor']);
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
        // **The name of this test is the assertion.** A batch is a `powershell.exe` start on the
        // store this capability exists for, so asking it about nothing would spend a process to
        // learn nothing, and the looping path already does nothing here because a `foreach` over
        // an empty list does nothing. This makes the two agree.
        //
        // **Nothing pays that cost today**, and saying so is the honest scope: `storedAmong()`'s
        // only caller passes `MachineIdentity::knownHarnesses()`, which is every `KnownAgent`
        // case -- 15 of them, and never none. This pins a private method's behavior against a
        // future caller, not a live bug.
        ->and($store->batches)->toBeEmpty();
});
