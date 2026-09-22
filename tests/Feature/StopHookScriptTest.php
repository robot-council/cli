<?php

declare(strict_types=1);

/**
 * The stop-hook script the README hands to every harness.
 *
 * **That block is product surface, not an illustration.** The README says to save it as
 * `robot-council-stop-hook`, make it executable, and point a hook at it, and the three harness
 * configurations below it all reference that one file. Before #88 nothing in this suite read
 * `README.md` at all, so the script could stop working and no run would report it.
 *
 * **The script is extracted from the README rather than retyped**, because a copy tests the copy.
 * An edit to the documented block is exactly what these tests exist to fail on.
 *
 * **The failure being guarded is the quiet one.** By the script's own design a payload it cannot
 * read ends the turn with the sink untouched, which is indistinguishable from a fleet with nothing
 * to say. A broken script announces itself nowhere, which is why a test has to.
 *
 * @command  vendor/bin/pest --compact tests/Feature/StopHookScriptTest.php
 */

use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Cursor's payload as it actually arrives: a UTF-8 BOM before the `{`, measured for #72.
 */
const CURSOR_BOM_PAYLOAD = "\xEF\xBB\xBF".'{"loop_count":0,"conversation_id":"c1"}';

const CURSOR_PAYLOAD = '{"loop_count":0,"conversation_id":"c1"}';

const CLAUDE_PAYLOAD = '{"session_id":"s1","hook_event_name":"Stop","stop_hook_active":false}';

const CLAUDE_CONTINUED_PAYLOAD = '{"session_id":"s1","hook_event_name":"Stop","stop_hook_active":true}';

/**
 * The line whose whole job is to be inert, quoted exactly as the README writes it.
 *
 * A nowdoc, so the `$` and the backslash escapes are the bytes the README holds rather than
 * anything PHP interprets.
 */
const BOM_STRIP_LINE = <<<'TXT'
payload=${payload#$'\xef\xbb\xbf'}
TXT;

/**
 * The stop-hook script, read out of the README.
 *
 * @return string The shell source of the documented block.
 */
function stopHookScript(): string
{
    $readme = file_get_contents(\dirname(__DIR__, 2).'/README.md');

    if ($readme === false) {
        throw new RuntimeException('README.md could not be read.');
    }

    // Anchored on both fences, so a later fenced block cannot extend the match. A README edit that
    // renames the fence or drops the shebang fails here, rather than quietly testing an empty
    // string -- which would pass every assertion below about what the script does NOT print.
    $found = preg_match('/^```bash\R(#!\/usr\/bin\/env bash\R.*?)^```$/ms', $readme, $matches);

    if ($found !== 1) {
        throw new RuntimeException('README.md no longer holds exactly one fenced bash block opening with the shebang.');
    }

    return $matches[1];
}

/**
 * A `bash` able to run the script, or null where this machine has none.
 *
 * **Gated on the capability rather than on the platform**, the way `hasWindowsPowerShell()` is: the
 * question is whether the documented script can run here, and `PHP_OS_FAMILY` answers a different
 * one. Windows is deliberately included rather than skipped -- the README's `.cmd` shim runs this
 * very script through Git Bash in production, so a blanket Windows skip would leave the wiring most
 * likely to break as the only wiring nothing checks.
 *
 * @return string|null The interpreter path, or null when there is none.
 */
function bashBinary(): ?string
{
    $onPath = (new ExecutableFinder)->find('bash');

    if ($onPath !== null && is_executable($onPath)) {
        return $onPath;
    }

    // Where Git for Windows puts it. `ExecutableFinder` misses it when `bash` is not on `PATH`,
    // which is the usual arrangement for a PowerShell-launched job.
    foreach (['C:\Program Files\Git\bin\bash.exe', 'C:\Program Files (x86)\Git\bin\bash.exe'] as $candidate) {
        if (is_executable($candidate)) {
            return $candidate;
        }
    }

    return null;
}

/**
 * Run a shell script against one payload, with `robot-council` stubbed.
 *
 * **The stub is a shell function rather than a file on `PATH`.** A file would have to carry an
 * executable bit, which Windows does not keep the way the assertion would need, and `PATH`
 * separators differ between the two platforms this runs on. A function is found ahead of any
 * `PATH` lookup on both, so the same harness drives both.
 *
 * @param  string  $script  The shell source to run.
 * @param  string  $payload  Raw bytes for stdin, BOM included where the case is about one.
 * @param  string|null  $line  What the stubbed `pending` prints, or null for a quiet fleet.
 * @return array{out: string, err: string, code: int|null} What the run produced.
 */
function runScript(string $script, string $payload, ?string $line): array
{
    $bash = bashBinary();

    if ($bash === null) {
        throw new RuntimeException('No bash on this machine.');
    }

    $directory = sys_get_temp_dir().'/rc-hook-'.bin2hex(random_bytes(6));

    if (! mkdir($directory) && ! is_dir($directory)) {
        throw new RuntimeException('Could not create '.$directory);
    }

    $stub = <<<'BASH'
        robot-council() {
            [ -n "${RC_STUB_LINE:-}" ] && printf '%s\n' "$RC_STUB_LINE"
            return 0
        }

        BASH;

    $path = $directory.'/hook.sh';

    file_put_contents($path, $stub.$script);

    // **Forward slashes, because the interpreter may be Git Bash.** `sys_get_temp_dir()` answers
    // with backslashes on Windows, and a backslash is an escape character to the shell that
    // receives the path. Windows itself accepts either separator, so this costs nothing on the
    // platform that does not need it.
    $process = new Process([$bash, str_replace('\\', '/', $path)], env: ['RC_STUB_LINE' => $line ?? ''], timeout: 30);

    $process->setInput($payload);
    $process->run();

    $result = [
        'out' => $process->getOutput(),
        'err' => $process->getErrorOutput(),
        'code' => $process->getExitCode(),
    ];

    array_map(unlink(...), glob($directory.'/*') ?: []);
    rmdir($directory);

    return $result;
}

/**
 * Run the documented script, optionally with shell appended to probe what it left behind.
 *
 * @param  string  $payload  Raw bytes for stdin.
 * @param  string|null  $line  What the stubbed `pending` prints, or null for a quiet fleet.
 * @param  string  $extra  Shell appended after the script.
 * @return array{out: string, err: string, code: int|null} What the run produced.
 */
function runStopHook(string $payload, ?string $line, string $extra = ''): array
{
    return runScript(stopHookScript().$extra, $payload, $line);
}

/**
 * The hook's answer, decoded.
 *
 * @param  string  $output  What the hook printed.
 * @return array<string, mixed> The decoded object.
 */
function hookAnswer(string $output): array
{
    $decoded = json_decode($output, true);

    if (! \is_array($decoded)) {
        throw new RuntimeException('The hook printed no JSON object. Got: '.var_export($output, true));
    }

    /** @var array<string, mixed> $decoded */
    return $decoded;
}

it('finds a bash to run the documented script with', function (): void {
    // **This is what keeps every skip below from reading like a pass.** Each of those is gated on
    // `bashBinary()`, so a locator that answered null everywhere would empty this file while
    // reporting green. Asserted on every platform on purpose: `ubuntu-latest` and `windows-latest`
    // both carry a bash, so a null here is a finding rather than a machine's business.
    expect(bashBinary())->not->toBeNull();
});

it('holds exactly one stop-hook script, and it is the documented one', function (): void {
    $script = stopHookScript();

    expect($script)->toContain('robot-council pending')
        ->and($script)->toContain('followup_message')
        ->and($script)->toContain('"decision" => "block"')
        // The line #84 added. Its absence is caught behaviorally further down; this catches a
        // README edit that drops it, which is the way it would actually go.
        ->and($script)->toContain(BOM_STRIP_LINE);
});

it('is syntactically valid shell', function (): void {
    $bash = bashBinary();

    $script = sys_get_temp_dir().'/rc-hook-syntax-'.bin2hex(random_bytes(6)).'.sh';

    file_put_contents($script, stopHookScript());

    $process = new Process([(string) $bash, '-n', str_replace('\\', '/', $script)], timeout: 30);
    $process->run();

    $code = $process->getExitCode();
    $error = $process->getErrorOutput();

    unlink($script);

    expect($code)->toBe(0, 'bash -n rejected the documented script: '.firstLineOf($error));
})->skip(fn (): bool => bashBinary() === null, 'No bash on this machine to check the script with.');

it('answers a Cursor payload with followup_message, BOM and all', function (): void {
    $result = runStopHook(CURSOR_BOM_PAYLOAD, 'rebase your branch from otherdev');

    expect($result['code'])->toBe(0)
        ->and(hookAnswer($result['out']))->toHaveKey('followup_message')
        ->and(hookAnswer($result['out'])['followup_message'])->toContain('rebase your branch from otherdev');
})->skip(fn (): bool => bashBinary() === null, 'No bash on this machine to run the script with.');

it('answers a Cursor payload without a BOM the same way', function (): void {
    // The control for the case above: it is the BOM that differs, and nothing else.
    $result = runStopHook(CURSOR_PAYLOAD, 'rebase your branch from otherdev');

    expect($result['code'])->toBe(0)
        ->and(hookAnswer($result['out']))->toHaveKey('followup_message');
})->skip(fn (): bool => bashBinary() === null, 'No bash on this machine to run the script with.');

it('answers a Claude Code payload with a block decision', function (): void {
    // **The shape is the whole point.** `pending` clears the sink as it reads, so a harness handed
    // a shape it does not understand discards events that are already gone.
    $result = runStopHook(CLAUDE_PAYLOAD, 'rebase your branch from otherdev');

    $answer = hookAnswer($result['out']);

    expect($result['code'])->toBe(0)
        ->and($answer)->toHaveKey('decision')
        ->and($answer['decision'])->toBe('block')
        ->and($answer)->not->toHaveKey('followup_message')
        ->and($answer['reason'])->toContain('rebase your branch from otherdev');
})->skip(fn (): bool => bashBinary() === null, 'No bash on this machine to run the script with.');

it('ends the turn when the harness says it already continued one', function (): void {
    // The loop guard. Without it a hook that always has something to say never lets a turn end.
    $result = runStopHook(CLAUDE_CONTINUED_PAYLOAD, 'there is news, and it must not be delivered');

    // `toBeEmpty()` rather than `toBe('')` because `rector` rewrites the second to the first, and
    // `empty('0')` is true -- so the emptiness assertion alone is weaker than it looks. The second
    // assertion is the one that carries the meaning: whatever was printed, the news was not in it.
    expect($result['code'])->toBe(0)
        ->and(trim($result['out']))->toBeEmpty()
        ->and($result['out'])->not->toContain('must not be delivered');
})->skip(fn (): bool => bashBinary() === null, 'No bash on this machine to run the script with.');

it('ends the turn quietly when the fleet has nothing to say', function (): void {
    // Empty is success and prints nothing. A script that printed "nothing waiting" would make
    // every turn continue forever.
    $result = runStopHook(CURSOR_BOM_PAYLOAD, null);

    expect($result['code'])->toBe(0)
        ->and(trim($result['out']))->toBeEmpty()
        // A hook that answered at all here would continue the turn, so the absence of a shape
        // matters as much as the absence of content.
        ->and($result['out'])->not->toContain('followup_message');
})->skip(fn (): bool => bashBinary() === null, 'No bash on this machine to run the script with.');

it('strips the BOM, so the payload it leaves behind can be parsed', function (): void {
    // **None of the cases above can fail when the strip is removed**, because the `case` matching
    // tolerates a leading BOM -- measured while building #84. So the strip needs an assertion that
    // looks at `$payload` itself, and a parse is what the strip exists to protect.
    //
    // `php` rather than `jq` on purpose: `jq` skips a leading BOM (`src/jv_parse.c`, since at least
    // `jq-1.6`), so it cannot tell the two apart. `php` refuses one, and `php` is what this script
    // already invokes.
    $probe = "\n".'RC_PAYLOAD="$payload" php -r '."'".'exit(json_decode(getenv("RC_PAYLOAD"), true) === null ? 9 : 0);'."'\n";

    $result = runStopHook(CURSOR_BOM_PAYLOAD, 'rebase your branch from otherdev', $probe);

    expect($result['code'])->toBe(0, 'the payload the script left behind did not parse: '.firstLineOf($result['err']));
})->skip(fn (): bool => bashBinary() === null, 'No bash on this machine to run the script with.');

it('would not parse without the strip, which is what makes the test above discriminate', function (): void {
    // The negative control. Without it, a probe that passed for some unrelated reason -- `php`
    // missing, the appended line never reached -- would read as the strip working.
    $probe = "\n".'RC_PAYLOAD="$payload" php -r '."'".'exit(json_decode(getenv("RC_PAYLOAD"), true) === null ? 9 : 0);'."'\n";

    $script = stopHookScript();
    $without = str_replace(BOM_STRIP_LINE, '', $script);

    // A replacement that matched nothing leaves the strip in place and the test passes against the
    // very thing it exists to refuse.
    expect($without)->not->toBe($script, 'the strip line was not found, so this control edited nothing');

    $result = runScript($without.$probe, CURSOR_BOM_PAYLOAD, 'rebase your branch from otherdev');

    expect($result['code'])->toBe(9, 'removing the strip did not make the payload unparseable: '.firstLineOf($result['err']));
})->skip(fn (): bool => bashBinary() === null, 'No bash on this machine to run the script with.');
