<?php

declare(strict_types=1);

namespace App\Support;

use Symfony\Component\Process\Process;
use Throwable;

/**
 * The process that launched this one, identified so that a later bridge can tell whether it shares it (#333).
 *
 * **A pid alone is not an identity**, because pids are reused: a Cursor that quit and relaunched can
 * land on its old helper's pid. So the identity is the pid AND its start time, which together name
 * one process for as long as the machine runs. Under Cursor the parent is `Cursor Helper:
 * mcp-process`, which outlives the bridge across a reload and dies with the application (#321).
 *
 * **Null wherever either half cannot be read**, and a caller must treat null as "not the same":
 * the only thing this decides is whether a bridge may join by itself, and not joining is the safe
 * direction.
 */
final readonly class ParentProcess
{
    /**
     * How long to wait for `ps` to say when the parent started.
     */
    private const int READ_TIMEOUT_SECONDS = 5;

    /**
     * @param  array{pid: int, started: string}|null  $known  A fixed identity to report instead of
     *                                                        reading this process's parent, which is
     *                                                        how a test stands in for another process.
     */
    public function __construct(private ?array $known = null) {}

    /**
     * The parent's pid and start time, or null when either cannot be read.
     *
     * @return array{pid: int, started: string}|null
     */
    public function identity(): ?array
    {
        if ($this->known !== null) {
            return $this->known;
        }

        // Absent on Windows, where how Cursor launches its servers has not been measured (#333)
        if (! \function_exists('posix_getppid')) {
            return null;
        }

        $pid = posix_getppid();

        // 1 is init or launchd, which a process is re-parented to when its parent has died
        if ($pid <= 1) {
            return null;
        }

        $started = PHP_OS_FAMILY === 'Linux' ? $this->linuxStart($pid) : $this->psStart($pid);

        return $started === null ? null : ['pid' => $pid, 'started' => $started];
    }

    /**
     * When the process started, as this boot's id and the clock ticks after it, from `/proc`.
     */
    private function linuxStart(int $pid): ?string
    {
        $stat = @file_get_contents('/proc/'.$pid.'/stat');

        if (! \is_string($stat)) {
            return null;
        }

        // After the command name, which is in parentheses and may itself hold spaces or parentheses:
        // the fields from the third on, of which `starttime` is the twenty-second
        $closing = strrpos($stat, ')');

        if ($closing === false) {
            return null;
        }

        $fields = explode(' ', trim(substr($stat, $closing + 2)));

        if (! isset($fields[19]) || preg_match('/^\d+$/', $fields[19]) !== 1) {
            return null;
        }

        // **With the boot it counts from**, since ticks since boot restart at every boot: a helper
        // that came back after a reboot with its old pid at the same tick would otherwise match
        $boot = @file_get_contents('/proc/sys/kernel/random/boot_id');

        return \is_string($boot) && trim($boot) !== '' ? trim($boot).':'.$fields[19] : null;
    }

    /**
     * When the process started, as `ps` prints it, on macOS and the other BSDs.
     */
    private function psStart(int $pid): ?string
    {
        try {
            // The C locale, so the same process reads the same way whatever the operator's settings
            $process = new Process(['ps', '-o', 'lstart=', '-p', (string) $pid], null, ['LC_ALL' => 'C'], null, self::READ_TIMEOUT_SECONDS);
            $process->run();
        } catch (Throwable) {
            return null;
        }

        $started = trim($process->getOutput());

        return $process->isSuccessful() && $started !== '' ? $started : null;
    }
}
