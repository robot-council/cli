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
use App\Support\Credentials\Credentials;
use App\Support\Credentials\InstallationChoice;
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
