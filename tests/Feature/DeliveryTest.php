<?php

declare(strict_types=1);

/**
 * Sink events delivered at least once: removed at the turn end after the one that printed them (cli#341).
 *
 * `pending` used to empty the sink before printing, so a stop hook killed at its timeout, on a
 * machine under load, lost every event in it. Now `pending` prints, marks what it printed, and
 * removes only what an earlier call marked.
 *
 * **One test kills a real `pending` part-way through printing**, because that is the claim: a
 * process that dies after reading the sink and before its output is consumed must not take the
 * events with it. It blocks the process on a pipe nobody reads, by giving it more to print than a
 * pipe holds, then kills it there.
 *
 * @command  vendor/bin/pest --compact tests/Feature/DeliveryTest.php
 */

use App\Support\PendingEvents;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Sleep;
use Tests\Fixtures\TwoStreamOutput;

const DELIVERY_SERVICE = 'https://delivery.example.test';

function deliverySink(): PendingEvents
{
    return new PendingEvents(DELIVERY_SERVICE, 'claude', 'delivery');
}

/**
 * A directive as the feed serializes it.
 *
 * @return array<string, mixed>
 */
function deliveryEvent(int $id, string $body = 'rebase your branch'): array
{
    return [
        'id' => $id,
        'type' => 'directive',
        'body' => $body,
        'meta' => [],
        'created_at' => '2026-09-29T00:00:00+00:00',
        'actor' => ['session_id' => 9, 'github_login' => 'otherdev', 'coordinator_direct' => true],
    ];
}

/**
 * Run `pending` for this sink, and return what it printed.
 */
function deliveryPending(): string
{
    $output = new TwoStreamOutput;

    expect(Artisan::call('pending', ['--service' => DELIVERY_SERVICE, '--harness' => 'claude', '--project' => 'delivery'], $output))->toBe(0);

    return $output->stdout();
}

/**
 * The raw entries in the sink file, delivered or not.
 *
 * @return list<mixed>
 */
function deliveryRaw(): array
{
    $contents = @file_get_contents(deliverySink()->path());
    $decoded = is_string($contents) && $contents !== '' ? json_decode($contents, true) : [];

    return is_array($decoded) ? array_values($decoded) : [];
}

beforeEach(function (): void {
    $this->state = sys_get_temp_dir().'/rc-delivery-'.bin2hex(random_bytes(6));
    putenv('XDG_STATE_HOME='.$this->state);
});

afterEach(function (): void {
    putenv('XDG_STATE_HOME');

    File::deleteDirectory($this->state);
});

it('prints what is waiting once, and removes it at the next turn end', function (): void {
    deliverySink()->add([deliveryEvent(1), deliveryEvent(2)]);

    $first = deliveryPending();

    // Printed, and still in the sink, marked, until a later turn end
    expect(substr_count($first, '[directive]'))->toBe(2)
        ->and(deliveryRaw())->toHaveCount(2)
        ->and(array_column(deliveryRaw(), PendingEvents::DELIVERED))->toHaveCount(2)
        ->and(deliverySink()->peek())->toBeEmpty();

    // The next turn end prints nothing again, and removes what the first one delivered
    expect(deliveryPending())->toBeEmpty()
        ->and(deliveryRaw())->toBeEmpty();
});

it('removes at a turn end only what an earlier one printed, never what arrived since', function (): void {
    deliverySink()->add([deliveryEvent(1)]);

    deliveryPending();

    // Arrives after that turn end printed the first
    deliverySink()->add([deliveryEvent(2, 'arrived later')]);

    $second = deliveryPending();

    expect($second)->toContain('arrived later')
        ->and($second)->not->toContain('rebase your branch')
        ->and(array_column(deliveryRaw(), 'id'))->toBe([2]);
});

it('does not mark an entry that changed between reading and marking', function (): void {
    deliverySink()->leaveNotice('bridge.capacity-changed', 'from 1 to 3');

    // A `pending` read it, and before it marked it, the bridge replaced it
    $read = deliverySink()->deliver();
    deliverySink()->leaveNotice('bridge.capacity-changed', 'from 1 to 2');
    deliverySink()->markDelivered($read);

    expect(deliveryPending())->toContain('from 1 to 2');
});

it('prints them again when the process that read them never marked them', function (): void {
    deliverySink()->add([deliveryEvent(1)]);

    // Read, and then the process died: nothing marked
    expect(deliverySink()->deliver())->toHaveCount(1);

    expect(deliveryPending())->toContain('rebase your branch');
});

it('keeps the events of a pending killed while it was printing them', function (): void {
    // More than a pipe holds, so the process blocks writing them to a pipe nobody reads
    $events = [];

    for ($id = 1; $id <= PendingEvents::MAX_EVENTS; $id++) {
        $events[] = deliveryEvent($id, str_repeat('x', 1000));
    }

    deliverySink()->add($events);

    $process = proc_open(
        [PHP_BINARY, base_path('robot-council'), 'pending', '--service='.DELIVERY_SERVICE, '--harness=claude', '--project=delivery'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        ['XDG_STATE_HOME' => $this->state, 'PATH' => (string) getenv('PATH'), 'HOME' => (string) getenv('HOME')],
    );

    if ($process === false) {
        throw new RuntimeException('Could not start `pending`.');
    }

    // Until it has read the sink and started writing, then stuck on the full pipe
    for ($waited = 0; $waited < 100 && proc_get_status($process)['running'] && deliveryRaw() !== [] && ! is_file(deliverySink()->turnMarkPath()); $waited++) {
        Sleep::for(100)->milliseconds();
    }

    Sleep::for(500)->milliseconds();

    // The control that it was stopped mid-print rather than finished: still running, blocked
    expect(proc_get_status($process)['running'])->toBeTrue();

    proc_terminate($process, 9);
    proc_close($process);

    // Nothing was lost: the next turn end prints every one of them
    expect(substr_count(deliveryPending(), '[directive]'))->toBe(PendingEvents::MAX_EVENTS);
})->skipOnWindows();
