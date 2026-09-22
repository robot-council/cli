<?php

declare(strict_types=1);

/**
 * The one line a harness's stop hook runs, and the file behind it.
 *
 * The bridge is long-running and holds the session, the credential and the feed cursor; a stop hook
 * is a short-lived process the harness spawns at a turn boundary and knows none of that. This
 * command is what closes the gap, so a hook does not have to know where the sink is or what shape
 * it has (cli#60).
 *
 * **Empty is success and prints nothing**, which is load-bearing rather than tidy: a hook that
 * injects whatever this writes has to let a turn end normally when the fleet has been quiet, and a
 * command that printed "nothing waiting" would make every turn continue forever.
 *
 * @command  vendor/bin/pest --compact tests/Feature/PendingCommandTest.php
 */
use App\Support\PendingEvents;
use Illuminate\Support\Facades\Artisan;

const PENDING_SERVICE = 'https://fleet.example.test';

/**
 * The sink the command will read, under a directory this test owns.
 */
function pendingSink(string $harness = 'claude', ?string $project = 'probe'): PendingEvents
{
    return new PendingEvents(PENDING_SERVICE, $harness, $project);
}

/**
 * One event as the feed serializes it.
 *
 * @return array<string, mixed> The event.
 */
function waitingEvent(string $body = 'rebase your branch'): array
{
    return [
        'id' => 12,
        'type' => 'directive',
        'body' => $body,
        'meta' => [],
        'created_at' => '2026-09-22T12:00:00+00:00',
        'actor' => ['session_id' => 9, 'github_login' => 'otherdev', 'coordinator_direct' => true],
    ];
}

beforeEach(function (): void {
    $this->stateDirectory = sys_get_temp_dir().'/rc-pending-'.bin2hex(random_bytes(6));

    putenv('XDG_STATE_HOME='.$this->stateDirectory);
    putenv('ROBOT_COUNCIL_SERVICE='.PENDING_SERVICE);
    putenv('ROBOT_COUNCIL_HARNESS=claude');
});

afterEach(function (): void {
    pendingSink()->forget();

    if (is_dir($this->stateDirectory)) {
        array_map(unlink(...), glob($this->stateDirectory.'/robot-council/pending/*') ?: []);
        @rmdir($this->stateDirectory.'/robot-council/pending');
        @rmdir($this->stateDirectory.'/robot-council');
        @rmdir($this->stateDirectory);
    }

    putenv('XDG_STATE_HOME');
    putenv('ROBOT_COUNCIL_SERVICE');
    putenv('ROBOT_COUNCIL_HARNESS');
});

it('prints what is waiting and clears it', function (): void {
    pendingSink()->add([waitingEvent()]);

    // `Artisan::call()` and the whole buffer, as the rest of this suite does. `expectsOutputToContain`
    // consumes one line per expectation, so two substrings from the same line fail the second.
    // The whole rendered line is the better assertion anyway: it is what a hook injects, provenance
    // included, because who said it is what makes event content weighable by something with shell
    // access.
    expect(Artisan::call('pending', ['--project' => 'probe']))->toBe(0)
        ->and(Artisan::output())->toContain('[directive] rebase your branch from otherdev');

    // Cleared, so the next turn does not read the same directive again and act on it twice.
    expect(pendingSink()->isEmpty())->toBeTrue();
});

it('prints nothing and succeeds when the fleet has been quiet', function (): void {
    expect(Artisan::call('pending', ['--project' => 'probe']))->toBe(0)
        ->and(trim(Artisan::output()))->toBeEmpty()
        ->and(pendingSink()->isEmpty())->toBeTrue();
});

it('leaves what is waiting alone when only peeking', function (): void {
    pendingSink()->add([waitingEvent()]);

    expect(Artisan::call('pending', ['--project' => 'probe', '--peek' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('rebase your branch')
        ->and(pendingSink()->isEmpty())->toBeFalse();
});

it('reads the sink of the harness it is told it is, and no other', function (): void {
    // **The failure this guards is silent.** A `pending` that resolved the harness differently from
    // the bridge beside it would read an empty sink and report a quiet fleet.
    pendingSink(harness: 'cursor')->add([waitingEvent('for cursor only')]);

    expect(Artisan::call('pending', ['--project' => 'probe']))->toBe(0)
        ->and(pendingSink(harness: 'cursor')->isEmpty())->toBeFalse()
        ->and(Artisan::call('pending', ['--project' => 'probe', '--harness' => 'cursor']))->toBe(0)
        ->and(Artisan::output())->toContain('for cursor only')
        ->and(pendingSink(harness: 'cursor')->isEmpty())->toBeTrue();
});

it('tells one project from another', function (): void {
    pendingSink(project: 'other-checkout')->add([waitingEvent('for the other checkout')]);

    expect(Artisan::call('pending', ['--project' => 'probe']))->toBe(0)
        ->and(pendingSink(project: 'other-checkout')->isEmpty())->toBeFalse();

    pendingSink(project: 'other-checkout')->forget();
});

it('refuses without a service, rather than reading some other sink', function (): void {
    putenv('ROBOT_COUNCIL_SERVICE');

    expect(Artisan::call('pending'))->toBe(1);
});

it('keeps only the newest events when the fleet outruns the agent', function (): void {
    $sink = pendingSink();

    // One more than the bound, so the oldest has to go. An agent idle for a weekend would otherwise
    // return to a file nobody can read and a turn that opens with a month of history.
    $sink->add(array_map(
        static fn (int $n): array => waitingEvent('event '.$n),
        range(1, PendingEvents::MAX_EVENTS + 1)
    ));

    $waiting = $sink->drain();

    expect($waiting)->toHaveCount(PendingEvents::MAX_EVENTS)
        ->and($waiting[0]['body'])->toBe('event 2')
        ->and($waiting[PendingEvents::MAX_EVENTS - 1]['body'])->toBe('event '.(PendingEvents::MAX_EVENTS + 1));
});

it('treats a corrupt sink as an empty one', function (): void {
    $sink = pendingSink();

    $sink->add([waitingEvent()]);

    // A cache of things to tell an agent. A half-written file is worth losing, and is not worth
    // stopping a bridge or a turn for.
    file_put_contents($sink->path(), '{ this is not json');

    expect($sink->drain())->toBeEmpty()
        ->and(Artisan::call('pending', ['--project' => 'probe']))->toBe(0);
});

it('forgets the sink entirely, which is what ending a session does', function (): void {
    $sink = pendingSink();

    $sink->add([waitingEvent()]);

    expect($sink->path())->toBeFile();

    $sink->forget();

    // Unread events name tasks and locks THIS session held, so leaving them for the next one would
    // hand it somebody else's work to react to.
    expect($sink->path())->not->toBeFile()
        ->and($sink->drain())->toBeEmpty();
});
