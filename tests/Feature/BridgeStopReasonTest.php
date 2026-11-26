<?php

declare(strict_types=1);

/**
 * Which end of its loop a bridge says it took (#396).
 *
 * The record of a stop is only as good as what the bridge noted on the way out, so each way the loop
 * can end is driven here and the reason read back. The first one noted wins, because a second
 * `stop()` with no reason is the same ending reached again.
 *
 * @command  vendor/bin/pest --compact tests/Feature/BridgeStopReasonTest.php
 */

use App\Support\Bridge;
use App\Support\StopReason;

it('notes stdin closed when the loop returns at the end of its input', function (): void {
    $bridge = new Bridge(null, 'https://stop.example.test', Bridge::HEARTBEAT_SECONDS);

    // Empty and already at end of file: the harness went away
    $bridge->run(tmpfile(), tmpfile(), fn (string $message): null => null);

    expect($bridge->stoppedBecause())->toBe(['reason' => StopReason::StdinClosed, 'facts' => []]);
});

it('notes nothing while the loop has not ended', function (): void {
    expect(new Bridge(null, 'https://stop.example.test', Bridge::HEARTBEAT_SECONDS)->stoppedBecause())->toBeNull();
});

it('notes the signal that stopped it, with its name', function (): void {
    $bridge = new Bridge(null, 'https://stop.example.test', Bridge::HEARTBEAT_SECONDS);

    $bridge->stop(StopReason::Signal, ['signal' => 'SIGTERM']);

    expect($bridge->stoppedBecause())->toBe(['reason' => StopReason::Signal, 'facts' => ['signal' => 'SIGTERM']]);
});

it('notes other when it was stopped and nothing said why', function (): void {
    $bridge = new Bridge(null, 'https://stop.example.test', Bridge::HEARTBEAT_SECONDS);

    $bridge->stop();

    expect($bridge->stoppedBecause())->toBe(['reason' => StopReason::Other, 'facts' => []]);
});

it('keeps the first reason when it is stopped again', function (): void {
    $bridge = new Bridge(null, 'https://stop.example.test', Bridge::HEARTBEAT_SECONDS);

    $bridge->stop(StopReason::Signal, ['signal' => 'SIGINT']);
    $bridge->stop();
    $bridge->stop(StopReason::SessionGone, ['status' => 409]);

    expect($bridge->stoppedBecause())->toBe(['reason' => StopReason::Signal, 'facts' => ['signal' => 'SIGINT']]);
});

it('names the signal it was stopped by, and keeps the interrupt for anything that is not terminate', function (int $received, string $name): void {
    $bridge = new Bridge(null, 'https://stop.example.test', Bridge::HEARTBEAT_SECONDS);

    // 15 stands in for the terminate signal, so this runs where `SIGTERM` is not defined
    $bridge->stopsOnSignal(15)($received);

    expect($bridge->stoppedBecause())->toBe(['reason' => StopReason::Signal, 'facts' => ['signal' => $name]]);
})->with([
    'terminate' => [15, 'SIGTERM'],
    'interrupt' => [2, 'SIGINT'],
]);

it('stops the loop when the signal handler runs, so the next pass forwards nothing', function (): void {
    $bridge = new Bridge(null, 'https://stop.example.test', Bridge::HEARTBEAT_SECONDS);

    $bridge->stopsOnSignal(15)(15);

    $stream = tmpfile();

    expect($stream)->not->toBeFalse();

    fwrite($stream, "{\"jsonrpc\":\"2.0\",\"id\":1}\n");
    rewind($stream);

    $said = [];
    $bridge->run($stream, tmpfile(), function (string $message) use (&$said): void {
        $said[] = $message;
    });

    // **A stopped loop reads nothing**, so the message is still unread: the stream is where it was.
    // The first reason stands too, because reaching the end of input afterwards does not replace it
    expect(ftell($stream))->toBe(0)
        ->and($said)->toBeEmpty()
        ->and($bridge->stoppedBecause()['reason'] ?? null)->toBe(StopReason::Signal);
});
