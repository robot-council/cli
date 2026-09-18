<?php

declare(strict_types=1);

namespace App\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use LaravelZero\Framework\Commands\Command;

/**
 * Reports what this command line is and where its design lives.
 *
 * The commands that do the work -- enrolling an installation, running the stdio MCP bridge, and
 * calling the API -- are designed in #1, which moved here from `robot-council/core` once its
 * blockers closed.
 */
#[Description('Show what this command line is, and what it can do yet')]
#[Signature('about')]
final class AboutCommand extends Command
{
    /**
     * Print the summary.
     */
    public function handle(): void
    {
        // Written with `line()` rather than the styled components, so that every word of it is
        // capturable by a test rather than rendered past the output the test can see
        $version = config('app.version');

        $this->line(sprintf('robot-council %s', \is_string($version) ? $version : 'unknown'));
        $this->newLine();
        $this->line('The command line for Robot Council, a coordination service for fleets of AI coding agents.');
        $this->line('It enrolls this machine, runs the stdio MCP bridge an agent harness talks to, and calls the API.');
        $this->line('Built so far: enroll. The bridge is #5 and the API command is #4.');
        $this->newLine();
        $this->line('The design is in robot-council/core#14, and this command line in #1.');
    }
}
