<?php

declare(strict_types=1);

/**
 * The one place `mcp`, `pending` and `api` say something that must not reach their stdout (#218).
 *
 * @command  vendor/bin/pest --compact tests/Feature/StderrTest.php
 */

use App\Support\Stderr;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\ConsoleOutput;

it('writes to the error stream of a console, and nothing to its output', function (): void {
    $console = new ConsoleOutput;
    $stdout = new BufferedOutput;
    $stderr = new BufferedOutput;

    // Both streams replaced, so the assertion reads exactly what each received.
    $console->setErrorOutput($stderr);

    Stderr::say($console, 'the bridge stopped');
    Stderr::say($stdout, 'never on a single buffer either');

    expect($stderr->fetch())->toBe('robot-council: the bridge stopped'.PHP_EOL)

        // An output with no error stream gets nothing: the fallback writes to the process's own
        // stderr instead, which is what keeps a test harness's single buffer clean.
        ->and($stdout->fetch())->toBeEmpty();
});
