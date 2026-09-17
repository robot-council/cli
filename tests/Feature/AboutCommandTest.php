<?php

declare(strict_types=1);

/**
 * The skeleton's one command, which is also the proof that the application boots and that a command
 * is discovered.
 *
 * The output is read back through `Artisan::output()` rather than asserted with
 * `expectsOutputToContain()`, which matches per write and could not be made to see a substring the
 * command demonstrably prints.
 *
 * @command  vendor/bin/pest --compact tests/Feature/AboutCommandTest.php
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;

it('says what the command line is and where its design lives', function (): void {
    expect(Artisan::call('about'))->toBe(0);

    $output = Artisan::output();

    expect($output)->toContain('robot-council')
        ->toContain('coordination service for fleets of AI coding agents')
        ->toContain('stdio MCP bridge')

        // The issues a reader needs, because nothing here is built yet
        ->toContain('robot-council/core#14')
        ->toContain('#33');
});

it('registers the command under its own name', function (): void {
    expect(array_keys($this->app->make(Kernel::class)->all()))->toContain('about');
});

it('prints no credential-shaped value, since it holds none', function (): void {
    Artisan::call('about');

    // The command line's whole reason for existing is to keep a token out of an agent's transcript,
    // so a command that prints nothing secret is the baseline this repository starts from
    expect(Artisan::output())->not->toMatch('/[0-9a-f]{32,}/')
        ->not->toContain('Bearer ');
});
