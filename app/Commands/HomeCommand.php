<?php

declare(strict_types=1);

namespace App\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use LaravelZero\Framework\Commands\Command;
use NunoMaduro\LaravelConsoleSummary\SummaryCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Exception\CommandNotFoundException;
use Symfony\Component\Console\Input\ArrayInput;

/**
 * `list`, and what runs when no command does: the summary, or a refusal naming a command that does
 * not exist.
 *
 * **Laravel Zero hands an unknown first argument to the default command, as its argument**
 * (`Kernel::ensureDefaultCommand()`), which suits an application with one command. The default was
 * the summary, which ignores its argument and succeeds, so `robot-council nosuchcommand` printed
 * the summary and exited `0` -- and a stop hook with a wrong command name would have handed the
 * summary to an agent as if it were fleet events, with nothing reporting an error (#248).
 *
 * **Named `list`, with `list`'s own argument and options**, so `robot-council --help` -- which
 * Symfony answers with the default command's help -- still describes `list` rather than a command
 * nobody can see, and `list --raw` keeps working. The summary itself is still
 * `NunoMaduro\LaravelConsoleSummary\SummaryCommand`'s, run from here.
 */
#[Description('List commands')]
#[Signature("list {namespace? : The namespace name} {--raw : To output raw command list} {--format=txt : The output format (txt, xml, json, or md)} {--short : To skip describing commands' arguments}")]
final class HomeCommand extends Command
{
    /**
     * Accept anything that follows an unknown name.
     *
     * **Because the unknown name is not the only thing that arrives.** A stop hook's own shape,
     * `pendng --project=org/repo`, reaches this command with an option it does not define, and
     * Symfony would refuse that option on stdout -- naming `--project` and never the command that
     * was actually wrong -- before `handle()` could say so.
     */
    public function __construct()
    {
        parent::__construct();

        $this->ignoreValidationErrors();
    }

    /**
     * Print the summary, or refuse the unknown command.
     *
     * @return int The exit code.
     */
    public function handle(): int
    {
        $name = $this->argument('namespace');
        $application = $this->getApplication();

        if (\is_string($name) && $name !== '' && $application instanceof Application) {
            try {
                $application->find($name);
            } catch (CommandNotFoundException $commandNotFoundException) {
                try {
                    // `list <namespace>` is a real use, and a namespace is not a command.
                    $application->findNamespace($name);
                } catch (CommandNotFoundException) {
                    // On stderr, so a caller reading stdout gets nothing it could mistake for
                    // output. Symfony's message names the command and suggests close matches.
                    $this->output->getErrorStyle()->writeln('robot-council: '.$commandNotFoundException->getMessage());

                    return self::FAILURE;
                }
            }
        }

        $summary = new SummaryCommand($this->laravel);
        $summary->setApplication($application);

        return $summary->run(new ArrayInput(array_filter([
            'namespace' => $name,
            '--raw' => $this->option('raw'),
            '--format' => $this->option('format'),
            '--short' => $this->option('short'),
        ], static fn (mixed $value): bool => ! in_array($value, [null, false, ''], true))), $this->output->getOutput());
    }
}
