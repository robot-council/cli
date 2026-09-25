<?php

declare(strict_types=1);

/**
 * What the bridge tells an agent when no stop hook will deliver its events (#306).
 *
 * **What these tests cannot show is that an agent reads the feed when told to.** They pin which
 * settings count as a hook, what the bridge says in its log, and which instructions reach the
 * agent -- the half that decided whether six seats heard their placements on 2026-09-25.
 *
 * @command  vendor/bin/pest --compact tests/Feature/StopHooksTest.php
 */

use App\Support\Bridge;
use App\Support\Joined;
use App\Support\StopHooks;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\Fixtures\TwoStreamOutput;

/**
 * A settings file with one `Stop` hook running the given command.
 *
 * @return array<string, mixed>
 */
function stopHookSettings(string $command = '/Users/someone/bin/robot-council-stop-hook'): array
{
    return ['hooks' => ['Stop' => [['hooks' => [['type' => 'command', 'command' => $command, 'timeout' => 20]]]]]];
}

/**
 * Write one settings file, creating its folder.
 *
 * @param  array<string, mixed>|string  $settings  Decoded settings, or raw text for a malformed file.
 */
function stopHookWrite(string $path, array|string $settings): void
{
    File::ensureDirectoryExists(\dirname($path));
    File::put($path, \is_string($settings) ? $settings : (string) json_encode($settings));
}

/**
 * The instructions an unjoined Claude Code bridge answers `initialize` with.
 */
function stopHookInstructions(bool $stopHook): string
{
    $in = tmpfile();
    fwrite($in, '{"jsonrpc":"2.0","id":0,"method":"initialize","params":{"protocolVersion":"2025-11-25"}}'."\n");
    rewind($in);

    $out = tmpfile();

    new Bridge(
        null,
        'https://fleet.example.test',
        channel: true,
        join: fn (array $arguments): Joined => throw new RuntimeException('not joined in this test'),
        stopHook: $stopHook,
    )->run($in, $out, fn (string $m): null => null);

    rewind($out);

    $instructions = data_get(json_decode((string) stream_get_contents($out), true), 'result.instructions');

    if (! \is_string($instructions)) {
        throw new RuntimeException('The initialize result carried no instructions.');
    }

    return $instructions;
}

beforeEach(function (): void {
    $this->root = str_replace('\\', '/', sys_get_temp_dir()).'/rc-hooks-'.bin2hex(random_bytes(6));
    $this->user = $this->root.'/user/.claude';
    $this->project = $this->root.'/project';

    File::ensureDirectoryExists($this->project);
});

afterEach(function (): void {
    File::deleteDirectory($this->root);
});

describe('finding a hook', function (): void {
    it('finds one in the user settings', function (): void {
        stopHookWrite($this->user.'/settings.json', stopHookSettings());

        $hooks = StopHooks::inspect($this->project, $this->user);

        expect($hooks->delivers())->toBeTrue()
            ->and($hooks->found)->toBe($this->user.'/settings.json')
            ->and($hooks->report())->toBe(['stop hook: found in '.$this->user.'/settings.json.']);
    });

    it("finds one in the project settings, and in the project's local settings", function (string $file): void {
        stopHookWrite($this->project.'/.claude/'.$file, stopHookSettings());

        expect(StopHooks::inspect($this->project, $this->user)->found)->toBe($this->project.'/.claude/'.$file);
    })->with(['settings.json', 'settings.local.json']);

    it('names the first file Claude Code reads when several name a hook', function (): void {
        stopHookWrite($this->user.'/settings.json', stopHookSettings());
        stopHookWrite($this->project.'/.claude/settings.json', stopHookSettings());

        expect(StopHooks::inspect($this->project, $this->user)->found)->toBe($this->user.'/settings.json');
    });

    it("finds one in the user's local settings", function (): void {
        stopHookWrite($this->user.'/settings.local.json', stopHookSettings());

        expect(StopHooks::inspect($this->project, $this->user)->delivers())->toBeTrue();
    });

    it('counts a hook whatever its command runs, since a wrapper script names nothing', function (): void {
        stopHookWrite($this->user.'/settings.json', stopHookSettings('bash -c "/opt/wrap.sh"'));

        expect(StopHooks::inspect($this->project, $this->user)->delivers())->toBeTrue();
    });

    it('finds none when no settings file exists', function (): void {
        $hooks = StopHooks::inspect($this->project, $this->user);

        expect($hooks->delivers())->toBeFalse()
            ->and($hooks->report())->toBe(['stop hook: none found; agents told to read the feed themselves.']);
    });

    it('finds none in settings with other hooks and no Stop hook', function (): void {
        stopHookWrite($this->user.'/settings.json', ['hooks' => ['PreToolUse' => [['hooks' => [['type' => 'command', 'command' => 'x']]]]]]);

        expect(StopHooks::inspect($this->project, $this->user)->delivers())->toBeFalse();
    });

    it('finds none in a Stop entry with no command', function (array $stop): void {
        stopHookWrite($this->user.'/settings.json', ['hooks' => ['Stop' => $stop]]);

        expect(StopHooks::inspect($this->project, $this->user)->delivers())->toBeFalse();
    })->with([
        'an empty list' => [[]],
        'a group with no hooks' => [[['matcher' => '']]],
        'a blank command' => [[['hooks' => [['type' => 'command', 'command' => '  ']]]]],
        'a command that is not a string' => [[['hooks' => [['type' => 'command', 'command' => 7]]]]],
        'a group that is not an object' => [['Stop']],
    ]);

    it('finds none when a settings file turns every hook off, even beside one that names a hook', function (): void {
        stopHookWrite($this->user.'/settings.json', stopHookSettings());
        stopHookWrite($this->project.'/.claude/settings.local.json', ['disableAllHooks' => true]);

        $hooks = StopHooks::inspect($this->project, $this->user);

        expect($hooks->delivers())->toBeFalse()
            ->and($hooks->report()[0])->toContain('sets disableAllHooks');
    });
});

describe('a settings file it cannot read', function (): void {
    it('treats a malformed file as naming no hook, says so, and still reads the others', function (): void {
        stopHookWrite($this->user.'/settings.json', '{"hooks": {"Stop": [');
        stopHookWrite($this->project.'/.claude/settings.json', stopHookSettings());

        $hooks = StopHooks::inspect($this->project, $this->user);

        expect($hooks->delivers())->toBeTrue()
            ->and($hooks->report())->toHaveCount(2)
            ->and($hooks->report()[0])->toStartWith('stop hook: could not read '.$this->user.'/settings.json');
    });

    it('treats a file that is not a JSON object as naming no hook', function (): void {
        stopHookWrite($this->user.'/settings.json', '"just a string"');

        $hooks = StopHooks::inspect($this->project, $this->user);

        expect($hooks->delivers())->toBeFalse()
            ->and($hooks->report()[0])->toContain('not a JSON object');
    });
});

describe('what the agent is told', function (): void {
    it("keeps today's instructions when a hook will deliver", function (): void {
        expect(stopHookInstructions(true))->toContain(Bridge::CHANNEL_INSTRUCTIONS)
            ->not->toContain(Bridge::CHANNEL_INSTRUCTIONS_WITHOUT_HOOK);
    });

    it('tells the agent to read the feed itself when none will', function (): void {
        $instructions = stopHookInstructions(false);

        expect($instructions)->toContain(Bridge::CHANNEL_INSTRUCTIONS_WITHOUT_HOOK)
            ->not->toContain(Bridge::CHANNEL_INSTRUCTIONS)
            ->and(Bridge::CHANNEL_INSTRUCTIONS_WITHOUT_HOOK)->toContain('`events_read`')->toContain('`task_list`');
    });
});

describe('the bridge at start', function (): void {
    beforeEach(function (): void {
        putenv('CLAUDE_CONFIG_DIR='.$this->user);
        putenv('XDG_STATE_HOME='.$this->root.'/state');
    });

    afterEach(function (): void {
        putenv('CLAUDE_CONFIG_DIR');
        putenv('XDG_STATE_HOME');
        putenv('ROBOT_COUNCIL_HARNESS');
    });

    it('says once, under Claude Code, which case applied', function (bool $withHook, string $says): void {
        putenv('ROBOT_COUNCIL_HARNESS=claude');

        if ($withHook) {
            stopHookWrite($this->user.'/settings.json', stopHookSettings());
        }

        $output = new TwoStreamOutput;

        Artisan::call('mcp', ['--service' => 'https://fleet.example.test'], $output);

        expect(substr_count($output->stderr(), 'stop hook:'))->toBe(1)
            ->and($output->stderr())->toContain($says)
            ->and($output->stdout())->toBeEmpty();
    })->with([
        'with a hook' => [true, 'stop hook: found in'],
        'without one' => [false, 'stop hook: none found'],
    ]);

    it('says nothing and inspects nothing for another harness', function (): void {
        putenv('ROBOT_COUNCIL_HARNESS=cursor');

        $output = new TwoStreamOutput;

        Artisan::call('mcp', ['--service' => 'https://fleet.example.test'], $output);

        expect($output->stderr())->not->toContain('stop hook:');
    });
});
