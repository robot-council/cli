<?php

declare(strict_types=1);

/**
 * Appends one line to a stop record, from a process of its own.
 *
 * Run by `StopRecordTest` many times at once, because a lock that holds inside one process says
 * nothing about forty bridges stopping together. Takes the record's path and a number, and writes the
 * number as the line's `status` so the test can count what survived.
 */

use App\Support\StopReason;
use App\Support\StopRecord;

require __DIR__.'/../../vendor/autoload.php';

$arguments = \is_array($_SERVER['argv'] ?? null) ? array_values($_SERVER['argv']) : [];
$path = $arguments[1] ?? null;
$number = $arguments[2] ?? null;

if (! \is_string($path) || ! \is_string($number)) {
    fwrite(STDERR, "usage: append-stop-record.php <path> <number>\n");

    exit(2);
}

$failure = new StopRecord($path)->append(StopReason::StdinClosed, ['status' => (int) $number]);

if ($failure !== null) {
    fwrite(STDERR, $failure."\n");

    exit(1);
}
