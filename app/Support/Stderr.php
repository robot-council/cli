<?php

declare(strict_types=1);

namespace App\Support;

use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Where a command says something that must never reach its stdout.
 *
 * **One body for every command whose stdout is somebody else's input**: `mcp`'s is a protocol
 * stream, `pending`'s is injected into an agent's turn, and `api`'s is a response body a caller
 * parses. The three held identical copies of this until #218, which is where a fix made to one
 * copy and not the others would have started. Each command still says, at its own `diagnostic()`,
 * why its stdout has to stay clean -- that reason differs, and it is the part a reader needs.
 */
final class Stderr
{
    /**
     * What every line starts with, so an operator can tell whose line it is.
     */
    public const string PREFIX = 'robot-council: ';

    /**
     * Write one line to stderr.
     *
     * @param  OutputInterface  $output  The command's output, whose error stream is used when it has one.
     * @param  string  $message  What to say.
     */
    public static function say(OutputInterface $output, string $message): void
    {
        if ($output instanceof ConsoleOutputInterface) {
            $output->getErrorOutput()->writeln(self::PREFIX.$message);

            return;
        }

        // A last resort that still avoids stdout. Reached when a command runs under a test harness
        // whose output is a single buffer rather than a console with two streams.
        file_put_contents('php://stderr', self::PREFIX.$message."\n");
    }
}
