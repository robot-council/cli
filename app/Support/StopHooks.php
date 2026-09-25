<?php

declare(strict_types=1);

namespace App\Support;

use JsonException;

/**
 * Whether Claude Code will run a `Stop` hook for the folder a bridge was launched in (#306).
 *
 * **The channel notice only wakes an agent; the stop hook is what delivers.** The bridge tells an
 * idle agent that a notice needs no work of its own, because the hook hands over the events when
 * the turn ends. With no hook, that instruction is exactly wrong: measured on 2026-09-25, six seats
 * with channels on and no `Stop` hook anywhere each woke within a second of a placement, ended the
 * turn, and saw nothing for over forty minutes. Every part reported healthy.
 *
 * **Best-effort, and deliberately generous about what counts as found.** Any `Stop` hook with a
 * command counts, whatever it runs, because the documented arrangement is a script whose name
 * says nothing about `robot-council pending`. A false "found" costs what happens today; a false
 * "none" costs an agent one extra read of the feed. `disableAllHooks` in any file read counts as
 * none, since Claude Code runs no hooks then, and only a hook whose `type` is `command` counts, since
 * Claude Code runs nothing else as one.
 *
 * Reads the four files Claude Code loads for a folder: the user's `settings.json` and
 * `settings.local.json` (under `CLAUDE_CONFIG_DIR` when that is set, otherwise `~/.claude`), and
 * the project's `.claude/settings.json` and `.claude/settings.local.json`, in that order, least
 * specific first. Managed (enterprise) settings are not read, so a managed `disableAllHooks` or
 * `allowManagedHooksOnly` is invisible here and reads as a hook found.
 */
final readonly class StopHooks
{
    /**
     * @param  string|null  $found  The first settings file naming a `Stop` hook, or null.
     * @param  list<string>  $unreadable  Settings files that exist but could not be read as JSON, with why.
     * @param  string|null  $disabledBy  A settings file that turns every hook off, or null.
     */
    private function __construct(
        public ?string $found,
        public array $unreadable,
        public ?string $disabledBy,
    ) {}

    /**
     * Look for a `Stop` hook in the settings Claude Code reads for a folder.
     *
     * @param  string  $projectDirectory  The folder the session was launched in.
     * @param  string|null  $userDirectory  Claude Code's user directory; resolved from the environment when null.
     */
    public static function inspect(string $projectDirectory, ?string $userDirectory = null): self
    {
        $userDirectory ??= self::userDirectory();

        $files = [];

        if ($userDirectory !== null) {
            $files[] = $userDirectory.'/settings.json';
            $files[] = $userDirectory.'/settings.local.json';
        }

        $files[] = rtrim($projectDirectory, '/\\').'/.claude/settings.json';
        $files[] = rtrim($projectDirectory, '/\\').'/.claude/settings.local.json';

        $found = null;
        $unreadable = [];
        $disabledBy = null;

        foreach ($files as $file) {
            if (! is_file($file)) {
                continue;
            }

            $contents = @file_get_contents($file);

            if ($contents === false) {
                $unreadable[] = $file.' (could not be read)';

                continue;
            }

            try {
                $settings = json_decode($contents, true, 64, JSON_THROW_ON_ERROR);
            } catch (JsonException $jsonException) {
                $unreadable[] = sprintf('%s (%s)', $file, $jsonException->getMessage());

                continue;
            }

            if (! \is_array($settings)) {
                $unreadable[] = $file.' (not a JSON object)';

                continue;
            }

            // The most specific file that says wins, as Claude Code resolves it: the files are read
            // from least to most specific, so a later answer replaces an earlier one. Measured on
            // 2.1.282: `true` in the project file and `false` in its local file ran the hook
            if (\is_bool($settings['disableAllHooks'] ?? null)) {
                $disabledBy = $settings['disableAllHooks'] ? $file : null;
            }

            if ($found === null && self::namesStopCommand($settings)) {
                $found = $file;
            }
        }

        return new self($disabledBy === null ? $found : null, $unreadable, $disabledBy);
    }

    /**
     * Whether a hook will deliver events at the end of a turn.
     */
    public function delivers(): bool
    {
        return $this->found !== null;
    }

    /**
     * What the bridge says about it in its log, one line per fact.
     *
     * @return list<string>
     */
    public function report(): array
    {
        $lines = array_map(
            static fn (string $file): string => sprintf('stop hook: could not read %s; treated as naming no hook.', $file),
            $this->unreadable,
        );

        $lines[] = match (true) {
            $this->disabledBy !== null => sprintf('stop hook: none will run, since %s sets disableAllHooks; an agent woken by a notice is told to read the feed itself.', $this->disabledBy),
            $this->found !== null => sprintf('stop hook: found in %s.', $this->found),
            default => 'stop hook: none found; an agent woken by a notice is told to read the feed itself.',
        };

        return $lines;
    }

    /**
     * Whether one settings file names a `Stop` hook with a command.
     *
     * @param  array<array-key, mixed>  $settings  The decoded file.
     */
    private static function namesStopCommand(array $settings): bool
    {
        $hooks = $settings['hooks'] ?? null;
        $stop = \is_array($hooks) ? ($hooks['Stop'] ?? null) : null;

        if (! \is_array($stop)) {
            return false;
        }

        foreach ($stop as $group) {
            $entries = \is_array($group) ? ($group['hooks'] ?? null) : null;

            if (! \is_array($entries)) {
                continue;
            }

            foreach ($entries as $entry) {
                // `type` is required: measured on 2.1.282, a hook with a command and no `type` did not run
                if (\is_array($entry) && ($entry['type'] ?? null) === 'command' && \is_string($entry['command'] ?? null) && trim($entry['command']) !== '') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Claude Code's user directory: `CLAUDE_CONFIG_DIR`, or `.claude` under the home directory.
     */
    private static function userDirectory(): ?string
    {
        $configured = getenv('CLAUDE_CONFIG_DIR');

        if (\is_string($configured) && trim($configured) !== '') {
            return rtrim($configured, '/\\');
        }

        foreach (['HOME', 'USERPROFILE'] as $variable) {
            $home = getenv($variable);

            if (\is_string($home) && trim($home) !== '') {
                return rtrim($home, '/\\').'/.claude';
            }
        }

        return null;
    }
}
