<?php

declare(strict_types=1);

/**
 * Reading stdin in a child so the bridge's loop can tick.
 *
 * **The property under test is that the loop wakes while stdin is quiet.** `stream_select()` does
 * not honor its timeout on a Windows pipe, so a bridge reading stdin directly only turns over when
 * the harness sends a frame -- and a harness sends nothing to an idle server for minutes at a time.
 * #131 holds the measurements; this holds the fix.
 *
 * These tests spawn real processes, because the whole defect lives in what the operating system
 * does with a pipe. A fake would only prove that this file agrees with itself.
 *
 * @command  vendor/bin/pest --compact tests/Feature/StdinReaderTest.php
 */

use App\Support\StdinReader;

/**
 * A path for one throwaway script, in the temporary directory rather than in a directory of its own.
 *
 * **No directory, deliberately.** A just-terminated process can still hold one on Windows: the
 * files unlink, `rmdir()` then fails on a directory that looks empty, and the `@` hides it.
 * Measured here -- empty directories accumulated on every run, and a one-second retry loop still
 * left three of five behind. A single file has nothing to remove afterwards but itself.
 */
function readerScript(): string
{
    return str_replace('\\', '/', sys_get_temp_dir()).'/rc-reader-'.bin2hex(random_bytes(6)).'.php';
}

/**
 * Remove a throwaway script, retrying briefly while the process that ran it lets go.
 */
function removeReaderScript(string $path): void
{
    for ($attempt = 0; $attempt < 20 && is_file($path); $attempt++) {
        @chmod($path, 0666);

        if (@unlink($path)) {
            return;
        }

        usleep(50_000);
    }
}

/**
 * Write a producer script.
 *
 * Built with `var_export()` rather than written out as a literal, so that nothing here depends on
 * an escape surviving this file and PHP in turn.
 *
 * @param  string  $path  Where to write it.
 * @param  list<string>  $lines  What it writes, in order.
 * @param  int  $pauseMicroseconds  How long it waits after the last line before exiting.
 */
function producerScript(string $path, array $lines, int $pauseMicroseconds): void
{
    $body = '<?php'."\n";

    foreach ($lines as $line) {
        $body .= 'fwrite(STDOUT, '.var_export($line."\n", true).'); fflush(STDOUT);'."\n";
    }

    $body .= 'usleep('.$pauseMicroseconds.');'."\n";

    file_put_contents($path, $body);
}

/**
 * Start a process whose stdout is a pipe this test can hand to the reader as stdin.
 *
 * @return array{resource, resource} The process handle and the pipe.
 */
function producerOf(string $script): array
{
    $process = proc_open(
        sprintf('%s %s', escapeshellarg(PHP_BINARY), escapeshellarg($script)),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );

    if (! is_resource($process)) {
        throw new RuntimeException('could not start the producer');
    }

    return [$process, $pipes[1]];
}

/**
 * Read whole newline-terminated frames from a stream, up to a count or a deadline.
 *
 * @param  resource  $stream  What to read.
 * @return list<string>
 */
function framesFrom($stream, int $wanted, float $seconds): array
{
    $frames = [];
    $buffer = '';
    $until = microtime(true) + $seconds;

    while (microtime(true) < $until && count($frames) < $wanted) {
        $read = [$stream];
        $write = [];
        $except = [];

        if (@stream_select($read, $write, $except, 0, 200_000) === 0) {
            continue;
        }

        $chunk = fread($stream, 65536);

        if ($chunk === false || ($chunk === '' && feof($stream))) {
            break;
        }

        $buffer .= $chunk;

        while (($break = strpos($buffer, "\n")) !== false) {
            $line = trim(substr($buffer, 0, $break));
            $buffer = substr($buffer, $break + 1);

            if ($line !== '') {
                $frames[] = $line;
            }
        }
    }

    return $frames;
}

it('forwards what stdin carries, in order', function (): void {
    $script = readerScript();

    producerScript($script, ['{"jsonrpc":"2.0","id":1}', '{"jsonrpc":"2.0","id":2}'], 3_000_000);

    [$producer, $pipe] = producerOf($script);
    $reader = new StdinReader($pipe);

    try {
        expect(framesFrom($reader->stream(), 2, 8.0))
            ->toBe(['{"jsonrpc":"2.0","id":1}', '{"jsonrpc":"2.0","id":2}']);
    } finally {
        $reader->stop();
        proc_terminate($producer);
        proc_close($producer);
        removeReaderScript($script);
    }
});

it('wakes while stdin is quiet, which is the whole point', function (): void {
    $script = readerScript();

    // One frame, then a long silence. The silence has to outlast the reader's own startup --
    // measured at about 1.1 seconds on this machine, since the child is this application booted
    // again -- or the window being measured would be over before it opened. An earlier version of
    // this test did not allow for that and recorded one idle wake where it wanted three.
    producerScript($script, ['{"jsonrpc":"2.0","id":1}'], 8_000_000);

    [$producer, $pipe] = producerOf($script);
    $reader = new StdinReader($pipe);

    try {
        // Drain the frame first, so what follows is genuinely quiet.
        expect(framesFrom($reader->stream(), 1, 8.0))->toHaveCount(1);

        $idleWakes = 0;
        $until = microtime(true) + 1.0;

        while (microtime(true) < $until) {
            $read = [$reader->stream()];
            $write = [];
            $except = [];

            if (@stream_select($read, $write, $except, 0, 200_000) === 0) {
                $idleWakes++;
            }
        }

        // **The assertion this whole change exists for.** A second at a 200ms timeout owes about
        // five; three keeps the test honest on a loaded machine while still failing outright if
        // the timeout never fires, which is what it does on a Windows pipe.
        expect($idleWakes)->toBeGreaterThan(3);
    } finally {
        $reader->stop();
        proc_terminate($producer);
        proc_close($producer);
        removeReaderScript($script);
    }
});

it('carries a message larger than any one read, in one piece', function (): void {
    $script = readerScript();

    // Larger than the 65,536-byte read and than any socket buffer, so it cannot arrive whole. The
    // bridge splits on newlines for exactly this reason: a 200,065-byte call once arrived as 24
    // fragments and every one was forwarded as its own malformed request.
    $huge = '{"jsonrpc":"2.0","id":1,"big":"'.str_repeat('x', 200_000).'"}';

    producerScript($script, [$huge], 3_000_000);

    [$producer, $pipe] = producerOf($script);
    $reader = new StdinReader($pipe);

    try {
        $frames = framesFrom($reader->stream(), 1, 15.0);

        expect($frames)->toHaveCount(1)
            ->and($frames[0])->toBe($huge)
            ->and(strlen($frames[0]))->toBeGreaterThan(200_000);
    } finally {
        $reader->stop();
        proc_terminate($producer);
        proc_close($producer);
        removeReaderScript($script);
    }
});

it('listens only on the loopback address', function (): void {
    $script = readerScript();

    producerScript($script, ['{"jsonrpc":"2.0","id":1}'], 8_000_000);

    [$producer, $pipe] = producerOf($script);
    $reader = new StdinReader($pipe);

    try {
        // Read off the live socket rather than off the code: what matters is what was bound, not
        // what the constructor asked for.
        $local = (string) stream_socket_get_name($reader->stream(), false);

        expect($local)->toStartWith('127.0.0.1:');
    } finally {
        $reader->stop();
        proc_terminate($producer);
        proc_close($producer);
        removeReaderScript($script);
    }
});

it('leaves no child behind when it stops', function (): void {
    $script = readerScript();

    // A stdin that stays open, so the child cannot exit of its own accord: if it is gone
    // afterwards, stopping it is what ended it.
    producerScript($script, ['{"jsonrpc":"2.0","id":1}'], 20_000_000);

    [$producer, $pipe] = producerOf($script);
    $reader = new StdinReader($pipe);
    $pid = $reader->pid();

    expect($pid)->toBeInt();

    // The control: it really is running before it is stopped, so its later absence is an absence
    // rather than a process that never started.
    expect(commandLineOf((int) $pid))->toContain('mcp:reader');

    $reader->stop();

    usleep(500_000);

    expect(commandLineOf((int) $pid))->toBeEmpty();

    proc_terminate($producer);
    proc_close($producer);
    removeReaderScript($script);
})->skip(
    fn (): bool => ! in_array(PHP_OS_FAMILY, ['Windows', 'Linux'], true),
    'Reading another process command line is implemented here for Windows and Linux only.'
);

it('sees the end of stdin, so a bridge learns the harness has gone', function (): void {
    $script = readerScript();

    producerScript($script, ['one'], 0);

    [$producer, $pipe] = producerOf($script);
    $reader = new StdinReader($pipe);

    try {
        $sawEof = false;
        $until = microtime(true) + 10.0;

        while (microtime(true) < $until) {
            $read = [$reader->stream()];
            $write = [];
            $except = [];

            if (@stream_select($read, $write, $except, 0, 200_000) === 0) {
                continue;
            }

            $chunk = fread($reader->stream(), 65536);

            if ($chunk === false || ($chunk === '' && feof($reader->stream()))) {
                $sawEof = true;

                break;
            }
        }

        // Without this the bridge cannot tell that the harness closed stdin, and a session would
        // outlive the editor that started it.
        expect($sawEof)->toBeTrue();
    } finally {
        $reader->stop();
        proc_terminate($producer);
        proc_close($producer);
        removeReaderScript($script);
    }
});

it('refuses a peer that cannot name the token', function (): void {
    $script = readerScript();

    // Connects to the advertised port and says the wrong thing, which is what any other process on
    // this machine could do.
    file_put_contents(
        $script,
        '<?php $s = stream_socket_client("tcp://127.0.0.1:".getenv("ROBOT_COUNCIL_READER_PORT"), $c, $e, 5);'
        .' fwrite($s, '.var_export("not-the-token\n", true).'); fflush($s); usleep(500000);'
    );

    $command = sprintf('%s %s', escapeshellarg(PHP_BINARY), escapeshellarg($script));

    expect(fn (): StdinReader => new StdinReader(null, null, $command))
        ->toThrow(RuntimeException::class, 'Something other than the stdin reader connected.');

    removeReaderScript($script);
});

it('never puts the token on the child command line', function (): void {
    // The acceptance criterion asks for the child's **own** command line, read from the operating
    // system, rather than for a reading of this code.
    //
    // **It needs a stdin that stays open.** Handed the test runner's, which is already at end of
    // file, the child connects, names its token and exits correctly -- and then there is no command
    // line left to read, which is how an earlier version of this failed its own control.
    $script = readerScript();

    producerScript($script, ['{"jsonrpc":"2.0","id":1}'], 8_000_000);

    [$producer, $pipe] = producerOf($script);
    $reader = new StdinReader($pipe);

    try {
        $pid = $reader->pid();

        expect($pid)->toBeInt();

        $own = commandLineOf((int) $pid);

        // **The control.** Without it, an unreadable command line and a clean one are the same
        // empty string, and the assertion below would pass by being blind.
        expect($own)->toContain('mcp:reader');

        expect(commandLinesMentioning('mcp:reader'))->each->not->toContain('ROBOT_COUNCIL_READER_TOKEN');
    } finally {
        $reader->stop();
        proc_terminate($producer);
        proc_close($producer);
        removeReaderScript($script);
    }
})->skip(
    fn (): bool => ! in_array(PHP_OS_FAMILY, ['Windows', 'Linux'], true),
    'Reading another process command line is implemented here for Windows and Linux only.'
);

/**
 * Run a PowerShell script from a file, because a command line cannot carry one.
 *
 * `shell_exec()` on Windows goes through `cmd.exe`, and `escapeshellarg()` quotes with double
 * quotes, so every pipe in a PowerShell pipeline breaks out of the quoting -- measured, the result
 * is `'Where-Object' is not recognized as an internal or external command`. A file has no command
 * line to mangle.
 */
function powerShell(string $script): string
{
    $path = str_replace('\\', '/', sys_get_temp_dir()).'/rc-reader-'.bin2hex(random_bytes(6)).'.ps1';

    file_put_contents($path, $script);

    $output = shell_exec(sprintf(
        'powershell -NoProfile -NonInteractive -ExecutionPolicy Bypass -File %s',
        escapeshellarg($path)
    ));

    @unlink($path);

    return (string) $output;
}

/**
 * One process's command line, or an empty string when it cannot be read.
 */
function commandLineOf(int $pid): string
{
    if (PHP_OS_FAMILY === 'Linux') {
        $raw = @file_get_contents(sprintf('/proc/%d/cmdline', $pid));

        return is_string($raw) ? str_replace("\0", ' ', $raw) : '';
    }

    return trim(powerShell(sprintf('(Get-CimInstance Win32_Process -Filter "ProcessId=%d").CommandLine', $pid)));
}

/**
 * The command lines of running processes that mention a string.
 *
 * @return list<string>
 */
function commandLinesMentioning(string $needle): array
{
    $lines = [];

    if (PHP_OS_FAMILY === 'Linux') {
        foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $path) {
            $raw = @file_get_contents($path);

            if (is_string($raw) && str_contains($raw, $needle)) {
                $lines[] = str_replace("\0", ' ', $raw);
            }
        }

        return $lines;
    }

    $output = powerShell(
        'Get-CimInstance Win32_Process | Where-Object { $_.CommandLine -like "*'.$needle.'*" } | ForEach-Object { $_.CommandLine }'
    );

    foreach (preg_split('/\R/', $output) ?: [] as $line) {
        if (trim($line) !== '') {
            $lines[] = $line;
        }
    }

    return $lines;
}
