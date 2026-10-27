<?php

declare(strict_types=1);

/**
 * Which directory a sink lives in, from the environment a process was started with (#268).
 *
 * **The defect this pins.** Windows does not set `HOME`. A bridge started from PowerShell fell
 * through to `sys_get_temp_dir()` and wrote under `%TEMP%`, while the stop hook, run under Git Bash
 * where `HOME` is set, read the user folder. Two sinks: nothing was delivered, and the channel
 * notice repeated.
 *
 * @command  vendor/bin/pest --compact tests/Feature/SinkDirectoryTest.php
 */
use App\Support\PendingEvents;

const SINK_DIRECTORY_VARIABLES = ['XDG_STATE_HOME', 'HOME', 'USERPROFILE'];

/**
 * Where a sink lands with exactly these variables set and the rest unset.
 *
 * @param  array<string, string>  $environment
 */
function sinkDirectoryWith(array $environment): string
{
    $saved = [];

    foreach (SINK_DIRECTORY_VARIABLES as $variable) {
        $saved[$variable] = getenv($variable);

        putenv(isset($environment[$variable]) ? $variable.'='.$environment[$variable] : $variable);
    }

    try {
        return str_replace('\\', '/', \dirname(new PendingEvents('https://fleet.example.test', 'claude')->path()));
    } finally {
        foreach ($saved as $variable => $value) {
            putenv($value === false ? $variable : $variable.'='.$value);
        }
    }
}

it('uses the user profile when there is no HOME, so the bridge and a Git Bash hook agree', function (): void {
    expect(sinkDirectoryWith(['USERPROFILE' => 'C:\\Users\\someone']))
        ->toBe('C:/Users/someone/.local/state/robot-council/pending')
        ->and(sinkDirectoryWith(['HOME' => 'C:\\Users\\someone', 'USERPROFILE' => 'C:\\Users\\someone']))
        ->toBe('C:/Users/someone/.local/state/robot-council/pending');
});

it('prefers HOME over USERPROFILE, and XDG_STATE_HOME over both', function (): void {
    expect(sinkDirectoryWith(['HOME' => '/home/a', 'USERPROFILE' => 'C:\\Users\\b']))
        ->toBe('/home/a/.local/state/robot-council/pending')
        ->and(sinkDirectoryWith(['XDG_STATE_HOME' => '/state', 'HOME' => '/home/a', 'USERPROFILE' => 'C:\\Users\\b']))
        ->toBe('/state/robot-council/pending');
});

it('falls back to the temporary directory only when no home is known', function (): void {
    expect(sinkDirectoryWith([]))
        ->toBe(str_replace('\\', '/', rtrim(sys_get_temp_dir(), '/\\')).'/.local/state/robot-council/pending');
});
