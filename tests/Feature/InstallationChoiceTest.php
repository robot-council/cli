<?php

declare(strict_types=1);

/**
 * Which stored credential a process presents, and the refusal when nothing says.
 *
 * **Every test here controls the harness environment, and that is not tidiness.** The detector
 * reads environment variables, and this suite runs inside a harness on a developer's machine and
 * inside none on CI -- measured on 2026-09-21: `claude` locally, `NULL` with `CLAUDECODE` and
 * `AI_AGENT` cleared. A test left to ambient detection passes on one side and fails on the other,
 * and which side depends on who runs it.
 *
 * @command  vendor/bin/pest --compact tests/Feature/InstallationChoiceTest.php
 */

use App\Support\Credentials\Credential;
use App\Support\Credentials\CredentialKey;
use App\Support\Credentials\Credentials;
use App\Support\Credentials\InstallationChoice;
use Tests\Fixtures\ProbeStore;
use Tests\Fixtures\RecordingStore;

const CHOICE_FLEET = 'https://fleet.example.test';
const CHOICE_CLAUDE = 'rcouncil_1|CHOICE-CLAUDE-0123456789abcdef';
const CHOICE_CURSOR = 'rcouncil_2|CHOICE-CURSOR-0123456789abcdef';

/**
 * Every environment variable that could name or betray a harness, cleared.
 *
 * Listed rather than guessed: these are the ones `AgentDetector` reads for the harnesses this
 * project wires, plus the variable this feature introduces.
 */
function forgetHarnessEnvironment(): void
{
    foreach (['ROBOT_COUNCIL_HARNESS', 'AI_AGENT', 'CLAUDECODE', 'CLAUDE_CODE', 'CURSOR_AGENT', 'CODEX_THREAD_ID'] as $variable) {
        putenv($variable);
    }
}

/**
 * A choice backed by a store holding exactly what is given.
 *
 * @param  array<string, string>  $entries  Keys to tokens.
 */
function choiceOver(array $entries): InstallationChoice
{
    $store = new RecordingStore;

    foreach ($entries as $key => $token) {
        $store->put($key, new Credential($token));
    }

    return new InstallationChoice(new Credentials([$store]));
}

beforeEach(fn () => forgetHarnessEnvironment());
afterEach(fn () => forgetHarnessEnvironment());

it('prefers the flag over everything else', function (): void {
    putenv('ROBOT_COUNCIL_HARNESS=cursor');
    putenv('CLAUDECODE=1');

    $choice = choiceOver([
        CHOICE_FLEET.'|claude' => CHOICE_CLAUDE,
        CHOICE_FLEET.'|cursor' => CHOICE_CURSOR,
    ]);

    expect($choice->for(CHOICE_FLEET, 'claude')->reveal())->toBe(CHOICE_CLAUDE);
});

it('prefers the environment variable over detection', function (): void {
    // Detection would say `claude`; the configuration says `cursor` and wins. This is the case
    // that matters in practice, because a harness config already carries ROBOT_COUNCIL_SERVICE.
    putenv('ROBOT_COUNCIL_HARNESS=cursor');
    putenv('CLAUDECODE=1');

    $choice = choiceOver([
        CHOICE_FLEET.'|claude' => CHOICE_CLAUDE,
        CHOICE_FLEET.'|cursor' => CHOICE_CURSOR,
    ]);

    expect($choice->for(CHOICE_FLEET, null)->reveal())->toBe(CHOICE_CURSOR);
});

it('falls back to detection when nobody named one', function (): void {
    putenv('CLAUDECODE=1');

    $choice = choiceOver([CHOICE_FLEET.'|claude' => CHOICE_CLAUDE]);

    expect($choice->for(CHOICE_FLEET, null)->reveal())->toBe(CHOICE_CLAUDE);
});

it('refuses when nothing resolves, even with exactly one credential stored', function (): void {
    // **The decision's sharp edge.** #21 rejected using the only stored credential, in favour of
    // one rule with nothing inferred. This is the test most likely to be quietly "fixed" later.
    $choice = choiceOver([CHOICE_FLEET.'|claude' => CHOICE_CLAUDE]);

    expect(fn (): Credential => $choice->for(CHOICE_FLEET, null))
        ->toThrow(RuntimeException::class, 'Could not tell which harness this is');
});

it('names what is enrolled when it refuses', function (): void {
    $choice = choiceOver([
        CHOICE_FLEET.'|claude' => CHOICE_CLAUDE,
        CHOICE_FLEET.'|cursor' => CHOICE_CURSOR,
    ]);

    try {
        $choice->for(CHOICE_FLEET, null);
    } catch (RuntimeException $runtimeException) {
        expect($runtimeException->getMessage())
            ->toContain('claude')
            ->toContain('cursor')
            ->toContain('--harness')
            ->toContain('ROBOT_COUNCIL_HARNESS')

            // The credential itself is never in a message a developer is told to read
            ->not->toContain(CHOICE_CLAUDE)
            ->not->toContain(CHOICE_CURSOR);

        return;
    }

    $this->fail('The choice did not refuse.');
});

it('says to enroll when nothing at all is stored', function (): void {
    expect(fn (): Credential => choiceOver([])->for(CHOICE_FLEET, null))
        ->toThrow(RuntimeException::class, 'robot-council enroll');
});

it('refuses a harness that resolved but has nothing stored', function (): void {
    putenv('ROBOT_COUNCIL_HARNESS=cursor');

    $choice = choiceOver([CHOICE_FLEET.'|claude' => CHOICE_CLAUDE]);

    expect(fn (): Credential => $choice->for(CHOICE_FLEET, null))
        ->toThrow(RuntimeException::class, 'not enrolled as `cursor`');
});

it('claims a legacy credential for a named harness, and moves it', function (): void {
    $store = new RecordingStore;
    $store->put(CHOICE_FLEET, new Credential(CHOICE_CLAUDE));

    $credentials = new Credentials([$store]);

    expect(new InstallationChoice($credentials)->for(CHOICE_FLEET, 'claude')->reveal())->toBe(CHOICE_CLAUDE)

        // Moved rather than copied: leaving it would let a second harness claim the same
        // installation's credential, which is the confusion harness keying exists to end
        ->and($store->stored)->toHaveKey(CHOICE_FLEET.'|claude')
        ->and($store->stored)->not->toHaveKey(CHOICE_FLEET);
});

it('does not claim a legacy credential on a merely detected harness', function (): void {
    // Detection is not a person saying which harness enrolled it. #21 refused that inference, and
    // this is the door it would come back through.
    putenv('CLAUDECODE=1');

    $store = new RecordingStore;
    $store->put(CHOICE_FLEET, new Credential(CHOICE_CLAUDE));

    expect(fn (): Credential => new InstallationChoice(new Credentials([$store]))->for(CHOICE_FLEET, null))
        ->toThrow(RuntimeException::class, 'stored before harnesses were told apart');

    // And it is still there, untouched, for the explicit claim to find
    expect($store->stored)->toHaveKey(CHOICE_FLEET);
});

it('reduces a named harness the way enroll does', function (): void {
    // `Claude` from a flag and `claude` from the detector are one harness, not two
    $choice = choiceOver([CHOICE_FLEET.'|claude' => CHOICE_CLAUDE]);

    expect($choice->for(CHOICE_FLEET, 'Claude')->reveal())->toBe(CHOICE_CLAUDE);
});

it('reads the bare-fleet key once when a named harness has nothing stored', function (): void {
    // **The saving is a read, but the reason is agreement.** `adopt()` reads that key and
    // `remedies()` used to read it again to write the same refusal. On a keychain that is two
    // subprocesses on a path already failing, and the second can return nothing the first did not
    // -- except a different answer, if the store breaks between them (#93).
    putenv('ROBOT_COUNCIL_HARNESS=claude');

    $store = new ProbeStore;

    $choice = new InstallationChoice(new Credentials([$store]));

    try {
        $choice->for(CHOICE_FLEET, null);

        $message = null;
    } catch (RuntimeException $runtimeException) {
        $message = $runtimeException->getMessage();
    }

    // **Leads with what is wrong, then what to do.** Asserted with `toStartWith` rather than
    // `toContain` because the two halves are concatenated: a message carrying both in the other
    // order contains each of them and reads backwards.
    expect($message)->not->toBeNull()
        ->and($message)->toStartWith('This machine is not enrolled as `claude`');

    $legacy = CredentialKey::legacy(CHOICE_FLEET);

    // Counted against the fake rather than read off the source, which is what the criterion asked
    // for: an implementation that reintroduced the second read would pass an inspection of either
    // method alone.
    expect(array_filter($store->reads, static fn (string $key): bool => $key === $legacy))->toHaveCount(1)
        // And it really did look, so a one that never read at all cannot satisfy the count above.
        ->and($store->reads)->toContain($legacy);
});

it('still looks for a legacy credential when nothing named a harness', function (): void {
    // The path with no earlier read to reuse: `unresolved()` is reached before any credential is
    // fetched, so the one read has to happen inside `remedies()`.
    $store = new ProbeStore;
    $store->put(CredentialKey::legacy(CHOICE_FLEET), new Credential(CHOICE_CLAUDE));

    $choice = new InstallationChoice(new Credentials([$store]));

    try {
        $choice->for(CHOICE_FLEET, null);

        $message = null;
    } catch (RuntimeException $runtimeException) {
        $message = $runtimeException->getMessage();
    }

    expect($message)->not->toBeNull()
        // Same reason as above: order carries meaning that `toContain` cannot see.
        ->and($message)->toStartWith('Could not tell which harness this is')
        // The legacy entry is reported, which is only possible if this path made the read.
        // **Asserted as one adjacent string, not as two fragments.** The situation and the remedy
        // are concatenated, and a message holding both in the other order contains each of them
        // while reading backwards and losing the sentence break between them.
        ->and($message)->toContain(
            'A credential stored before harnesses were told apart is here. '
            .'Pass --harness=<the harness it was enrolled as> to claim it'
        );
});

it('says to enroll when nothing named a harness and there is no legacy credential', function (): void {
    // The control for the test above, against an empty store. Without it, a `remedies()` that
    // always reported a legacy entry would satisfy that assertion.
    $store = new ProbeStore;

    $choice = new InstallationChoice(new Credentials([$store]));

    try {
        $choice->for(CHOICE_FLEET, null);

        $message = null;
    } catch (RuntimeException $runtimeException) {
        $message = $runtimeException->getMessage();
    }

    expect($message)->not->toBeNull()
        ->and($message)->toContain('Run `robot-council enroll` first.')
        ->and($message)->not->toContain('stored before harnesses were told apart is here');
});

it('keeps naming a remedy when the store breaks while the refusal is being written', function (): void {
    // `remedies()` runs while the process is already refusing. A store that has broken since the
    // command started must not replace a refusal that names a remedy with one that names none, so
    // an unreadable legacy entry is reported as absent -- which sends the operator to `enroll`,
    // the right advice whether the entry is missing or merely unreadable.
    $store = new ProbeStore;
    $store->unreadable = [CredentialKey::legacy(CHOICE_FLEET)];

    $choice = new InstallationChoice(new Credentials([$store]));

    try {
        $choice->for(CHOICE_FLEET, null);

        $message = null;
    } catch (RuntimeException $runtimeException) {
        $message = $runtimeException->getMessage();
    }

    expect($message)->not->toBeNull()
        ->and($message)->toContain('Run `robot-council enroll` first.');
});
