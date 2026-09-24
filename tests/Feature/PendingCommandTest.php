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

use App\Support\Bridge;
use App\Support\PendingEvents;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Sleep;

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

it('peeks without writing the sink at all, not merely without emptying it', function (): void {
    // **The sink is written with whitespace no encoder here produces.** That is what makes this
    // test discriminate: the first `--peek` drained and wrote the events back, which re-encoded
    // them compact, so the file changed even though it ended up holding the same events
    // (cli#71). Asserting "still non-empty afterwards" passed against that. Asserting the BYTES
    // does not.
    $sink = pendingSink();

    $sink->add([waitingEvent('keep me exactly as I am')]);

    $pretty = json_encode($sink->drain(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

    file_put_contents($sink->path(), $pretty);

    expect(Artisan::call('pending', ['--project' => 'probe', '--peek' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('keep me exactly as I am')
        // Byte-for-byte, so a truncate-and-rewrite is visible even when it restores the same
        // events. A write is what loses a concurrent append, whether or not it loses this one.
        ->and(file_get_contents($sink->path()))->toBe($pretty);
});

it('peeks a sink it cannot write, which is the same property observed from outside', function (): void {
    $sink = pendingSink();

    $sink->add([waitingEvent('readable but not writable')]);

    chmod($sink->path(), 0o400);

    // The guard is the control: where the mode does not actually deny writing -- running as root,
    // or a filesystem that ignores it -- this proves nothing and says so rather than passing.
    if (is_writable($sink->path())) {
        chmod($sink->path(), 0o600);
        $this->markTestSkipped('The sink is still writable at mode 0400, so this cannot discriminate.');
    }

    try {
        expect(Artisan::call('pending', ['--project' => 'probe', '--peek' => true]))->toBe(0)
            ->and(Artisan::output())->toContain('readable but not writable');
    } finally {
        chmod($sink->path(), 0o600);
    }
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

it('keeps a full sink intact across a peek, and still takes the next event', function (): void {
    // Criteria 2 and 3 of cli#71, and they are **consequences rather than races**. The old
    // `--peek` drained and wrote back, so an append landing in that window was re-ordered behind
    // older events and, at the bound, dropped: `write()` keeps the TAIL, and the tail was the
    // peeked events. A peek that performs no write cannot do either, whatever the timing, which
    // is why this is asserted deterministically instead of with sleeping processes.
    $sink = pendingSink();

    $sink->add(array_map(
        static fn (int $n): array => waitingEvent('event '.$n),
        range(1, PendingEvents::MAX_EVENTS)
    ));

    // **Re-encoded with whitespace before the peek, and that is what makes the byte assertion
    // below discriminate.** Compared against a sink the store itself wrote, a drain-and-write-back
    // produces byte-identical output -- the same encoder, the same events -- so the comparison
    // would pass against the very implementation this test exists to refuse. Measured: without
    // this line the test stays green with the old `peek()` planted.
    $raw = json_encode($sink->drain(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

    file_put_contents($sink->path(), $raw);

    $before = $sink->peek();

    expect($before)->toHaveCount(PendingEvents::MAX_EVENTS)
        ->and($before[0]['body'])->toBe('event 1')
        ->and(file_get_contents($sink->path()))->toBe($raw);

    // The append a concurrent bridge would have made.
    $sink->add([waitingEvent('arrived during the peek')]);

    $after = $sink->drain();

    expect($after)->toHaveCount(PendingEvents::MAX_EVENTS)
        // The newcomer is kept and is LAST, rather than displaced behind what the peek put back.
        ->and($after[PendingEvents::MAX_EVENTS - 1]['body'])->toBe('arrived during the peek')
        // The oldest went, which is the bound doing its job rather than the peek losing anything.
        ->and($after[0]['body'])->toBe('event 2');
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

it('drops the fleet events a session leaves behind, which is what ending one does', function (): void {
    $sink = pendingSink();

    $sink->add([waitingEvent()]);

    expect($sink->path())->toBeFile();

    $sink->clearFleetEvents();

    // Unread events name tasks and locks THIS session held, so leaving them for the next one would
    // hand it somebody else's work to react to.
    expect($sink->drain())->toBeEmpty();
});

it('keeps what the bridge wrote about the ending, which the next session is the reader of', function (): void {
    $sink = pendingSink();

    $sink->add([
        waitingEvent(),
        ['type' => Bridge::SESSION_ENDED, 'body' => 'the fleet ended it'],
    ]);

    $sink->clearFleetEvents();

    // **The one entry in the sink written FOR the reader that comes after.** Clearing it would
    // leave an agent starting over with nothing anywhere saying why, which is #188 exactly.
    $waiting = $sink->drain();

    expect($waiting)->toHaveCount(1)
        ->and($waiting[0]['type'])->toBe('bridge.session-ended');
});

it('clears a fleet event that merely mentions the prefix, rather than anything containing it', function (): void {
    // The negative control for the filter, and only the TYPE can supply it: the predicate reads
    // `type` and nothing else, so a fixture carrying the prefix in its body behaves identically
    // under `str_starts_with` and `str_contains` and proves nothing. Both of these would be kept
    // by a `str_contains`, and a fleet event kept across a session boundary is the defect the
    // clearing exists to prevent.
    $sink = pendingSink();

    $sink->add([
        ['type' => 'not-a-bridge.notice', 'body' => 'the prefix, one character in'],
        ['type' => 'task.claimed.bridge.echo', 'body' => 'the prefix, in the middle'],
    ]);

    $sink->clearFleetEvents();

    expect($sink->drain())->toBeEmpty();
});

/**
 * A second handle on the sink holding its exclusive lock, the way a stuck reader would.
 *
 * @return resource The handle, which the caller unlocks and closes.
 */
function holdSinkLock(PendingEvents $sink): mixed
{
    $holder = fopen($sink->path(), 'c+');

    if ($holder === false) {
        throw new RuntimeException('Could not open the sink to hold its lock.');
    }

    expect(flock($holder, LOCK_EX | LOCK_NB))->toBeTrue();

    return $holder;
}

it('clears without waiting when nothing else holds the sink', function (): void {
    Sleep::fake();

    $sink = pendingSink();

    $sink->add([
        waitingEvent(),
        ['type' => Bridge::SESSION_ENDED, 'body' => 'the fleet ended it'],
    ]);

    $said = [];

    $sink->clearFleetEvents(function (string $message) use (&$said): void {
        $said[] = $message;
    });

    // The uncontended path, asserted apart from the contended ones: fleet events go, the bridge's
    // own entry stays, and the retry loop never ran.
    $waiting = $sink->drain();

    expect($waiting)->toHaveCount(1)
        ->and($waiting[0]['type'])->toBe(Bridge::SESSION_ENDED)
        ->and($said)->toBeEmpty();

    Sleep::assertNeverSlept();
});

it('gives up on a sink held past the wait, leaving it unchanged and saying so once', function (): void {
    Sleep::fake();

    $sink = pendingSink();

    $sink->add([waitingEvent()]);

    $before = file_get_contents($sink->path());
    $holder = holdSinkLock($sink);
    $said = [];

    // **A blocking lock here held the bridge open behind a stuck reader after `SIGTERM`** (#228).
    // Faked, so the whole wait is counted rather than spent.
    $sink->clearFleetEvents(function (string $message) use (&$said): void {
        $said[] = $message;
    });

    flock($holder, LOCK_UN);
    fclose($holder);

    expect(file_get_contents($sink->path()))->toBe($before)
        ->and($said)->toHaveCount(1)
        ->and($said[0])->toContain($sink->path())
        ->toContain('2 seconds');

    Sleep::assertSleptTimes(intdiv(PendingEvents::CLEAR_WAIT_MILLISECONDS, PendingEvents::CLEAR_RETRY_MILLISECONDS));
    Sleep::assertSequence(array_fill(
        0,
        intdiv(PendingEvents::CLEAR_WAIT_MILLISECONDS, PendingEvents::CLEAR_RETRY_MILLISECONDS),
        Sleep::for(PendingEvents::CLEAR_RETRY_MILLISECONDS)->milliseconds(),
    ));
});

it('waits out a lock released inside the wait, and then clears', function (): void {
    Sleep::fake();

    $sink = pendingSink();

    $sink->add([waitingEvent()]);

    $holder = holdSinkLock($sink);
    $slept = 0;

    // The holder lets go on the third sleep, well inside the wait.
    Sleep::whenFakingSleep(function () use (&$slept, $holder): void {
        if (++$slept === 3) {
            flock($holder, LOCK_UN);
        }
    });

    $said = [];

    $sink->clearFleetEvents(function (string $message) use (&$said): void {
        $said[] = $message;
    });

    fclose($holder);

    expect($sink->drain())->toBeEmpty()
        ->and($said)->toBeEmpty();

    Sleep::assertSleptTimes(3);
});

it('renders what the bridge wrote without attributing it to a session', function (): void {
    // **The fallback for a missing actor is `from an unnamed session`**, which would tell a reader
    // the fleet delivered this -- something it structurally cannot do for a session about itself
    // (#175), arriving through the one door the `bridge.` prefix was chosen to close.
    pendingSink()->add([[
        'type' => Bridge::SESSION_ENDED,
        'body' => 'The fleet ended this session.',
        'created_at' => '2026-09-24T18:00:00+00:00',
    ]]);

    expect(Artisan::call('pending', ['--project' => 'probe']))->toBe(0)
        ->and(Artisan::output())
        ->toContain('[bridge.session-ended] The fleet ended this session. at 2026-09-24T18:00:00+00:00')
        ->not->toContain('unnamed session');
});

it('still names who said a fleet event, which is what makes its content weighable', function (): void {
    // The negative control for the line above. Dropping attribution everywhere would pass that
    // test and remove the provenance #14 threat model turns on: event bodies are other agents
    // words, reaching something with shell access.
    pendingSink()->add([waitingEvent()]);

    expect(Artisan::call('pending', ['--project' => 'probe']))->toBe(0)
        ->and(Artisan::output())->toContain('from otherdev');
});

it('keeps attribution on a fleet event whose type merely contains the prefix', function (): void {
    // The negative control the renderer was missing, and the mutation it guards against is the
    // expensive one: a `str_contains` here STRIPS provenance from a genuine fleet event, and who
    // said it is what makes event content weighable by something with shell access.
    pendingSink()->add([[
        'type' => 'not-a-bridge.notice',
        'body' => 'from the fleet, prefix one character in',
        'actor' => ['github_login' => 'otherdev'],
    ]]);

    expect(Artisan::call('pending', ['--project' => 'probe']))->toBe(0)
        ->and(Artisan::output())->toContain('from otherdev');
});

it('replaces an earlier notice of the same type rather than stacking another', function (): void {
    $sink = pendingSink();

    $sink->leaveNotice(Bridge::SESSION_ENDED, 'the first ending');
    $sink->leaveNotice(Bridge::SESSION_ENDED, 'the second ending');

    $waiting = $sink->drain();

    expect($waiting)->toHaveCount(1)
        ->and($waiting[0]['body'])->toBe('the second ending');
});

it('leaves a notice of a different type alone, so replacement is per type and not a wipe', function (): void {
    // The negative control: replacing everything local would pass the test above and silently
    // destroy any second kind of notice the bridge ever learns to write.
    $sink = pendingSink();

    $sink->leaveNotice(Bridge::SESSION_ENDED, 'the ending');
    $sink->leaveNotice(PendingEvents::LOCAL_PREFIX.'something-else', 'a different notice');

    expect($sink->drain())->toHaveCount(2);
});
