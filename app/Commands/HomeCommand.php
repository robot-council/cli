<?php

declare(strict_types=1);

namespace App\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Exception\CommandNotFoundException;

/**
 * What runs when no command does: the summary, or a refusal naming the command that does not exist.
 *
 * **Laravel Zero hands an unknown first argument to the default command, as its argument**
 * (`Kernel::ensureDefaultCommand()`), which suits an application with one command. The default was
 * the summary, which ignores its argument and succeeds, so `robot-council nosuchcommand` printed
 * the summary and exited `0` -- and a stop hook with a wrong command name would have handed the
 * summary to an agent as if it were fleet events, with nothing reporting an error (#248).
 */
#[Description('Show the commands, or say that the one asked for does not exist')]
#[Signature('home {name? : The command asked for, when it does not exist}')]
final class HomeCommand extends Command
{
    /**
     * Print the summary, or refuse the unknown command.
     *
     * @return int The exit code.
     */
    public function handle(): int
    {
        $name = $this->argument('name');

        if (\is_string($name) && $name !== '') {
            try {
                $this->getApplication()?->find($name);
            } catch (CommandNotFoundException $commandNotFoundException) {
                // On stderr, so a caller reading stdout gets nothing it could mistake for output.
                // Symfony's message names the command and suggests close matches.
                $this->output->getErrorStyle()->writeln('robot-council: '.$commandNotFoundException->getMessage());

                return self::FAILURE;
            }
        }

        return $this->call('list');
    }
}
