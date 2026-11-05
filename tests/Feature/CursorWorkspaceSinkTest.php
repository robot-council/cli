<?php

declare(strict_types=1);

/**
 * A Cursor seat's sink is keyed by its workspace folder, so one application runs one seat per repository (#356).
 *
 * Cursor ran one bridge for the whole application, and every window shared the sink that bridge
 * wrote (#327). A project-level `.cursor/mcp.json` server runs one bridge per repository, in that
 * repository, and the stop hook learns the chat's folder from the `workspace_roots` of its stdin
 * JSON. These tests pin the three things that must hold: two workspaces never share a sink, Claude
 * Code's keying is the one it always had, and an upgrade strands no pending event.
 *
 * Folders are real directories outside any repository, so the checkout falls back to the folder
 * itself and no `git` answer is faked.
 *
 * @command  vendor/bin/pest --compact tests/Feature/CursorWorkspaceSinkTest.php
 */
use App\Support\HookPayload;
use App\Support\PendingEvents;
use Illuminate\Support\Facades\Artisan;
use Tests\Fixtures\TwoStreamOutput;

const WORKSPACE_SERVICE = 'https://workspace.example.test';

/**
 * One event as the feed serializes it.
 *
 * @return array<string, mixed>
 */
function workspaceEvent(string $body): array
{
    return [
        'id' => 21,
        'type' => 'directive',
        'body' => $body,
        'meta' => [],
        'created_at' => '2026-10-01T12:00:00+00:00',
        'actor' => ['session_id' => 9, 'github_login' => 'otherdev', 'coordinator_direct' => true],
    ];
}

/**
 * A folder this test created, as the checkout resolution spells it.
 */
function workspaceFolder(string $path): string
{
    return realpath($path) ?: throw new RuntimeException('Could not resolve '.$path);
}

/**
 * A bridge's sink for a workspace, as a project-level server started in that folder builds it.
 */
function workspaceSink(string $folder, string $harness = 'cursor'): PendingEvents
{
    return new PendingEvents(WORKSPACE_SERVICE, $harness, null, $folder);
}

/**
 * Run the stop hook's command with a stdin payload, and return what it printed.
 *
 * @param  array<string, mixed>|string  $payload  What the harness writes to the hook's stdin.
 */
function workspaceHook(array|string $payload, string $harness = 'cursor'): string
{
    $stream = fopen('php://memory', 'r+') ?: throw new RuntimeException('Could not open a memory stream.');

    fwrite($stream, \is_array($payload) ? json_encode($payload, JSON_THROW_ON_ERROR) : $payload);
    rewind($stream);

    app()->instance(HookPayload::class, new HookPayload($stream));

    expect(Artisan::call('pending', ['--harness' => $harness]))->toBe(0);

    return Artisan::output();
}

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/rc-workspace-'.bin2hex(random_bytes(6));

    foreach (['one', 'two', 'state', 'home'] as $folder) {
        mkdir($this->root.'/'.$folder, 0o700, true);
    }

    $this->one = workspaceFolder($this->root.'/one');
    $this->two = workspaceFolder($this->root.'/two');

    putenv('XDG_STATE_HOME='.$this->root.'/state');
    putenv('ROBOT_COUNCIL_SERVICE='.WORKSPACE_SERVICE);
    putenv('ROBOT_COUNCIL_HARNESS=cursor');
    putenv('CURSOR_PROJECT_DIR');
    putenv('CLAUDE_PROJECT_DIR');
});

afterEach(function (): void {
    foreach (glob($this->root.'/state/robot-council/pending/*') ?: [] as $file) {
        @unlink($file);
    }

    foreach (['state/robot-council/pending', 'state/robot-council', 'state', 'one', 'two', 'home'] as $folder) {
        @rmdir($this->root.'/'.$folder);
    }

    @rmdir($this->root);

    putenv('XDG_STATE_HOME');
    putenv('ROBOT_COUNCIL_SERVICE');
    putenv('ROBOT_COUNCIL_HARNESS');
    putenv('CURSOR_PROJECT_DIR');
    putenv('CLAUDE_PROJECT_DIR');
    putenv('HOME');
    app()->forgetInstance(HookPayload::class);
});

describe('two workspaces', function (): void {
    it('write to different sinks, and each hook reads only its own', function (): void {
        workspaceSink($this->one)->add([workspaceEvent('for the first repository')]);
        workspaceSink($this->two)->add([workspaceEvent('for the second repository')]);

        expect(workspaceSink($this->one)->path())->not->toBe(workspaceSink($this->two)->path());

        $first = workspaceHook(['workspace_roots' => [$this->one], 'loop_count' => 0]);
        $second = workspaceHook(['workspace_roots' => [$this->two], 'loop_count' => 0]);

        expect($first)->toContain('for the first repository')->not->toContain('for the second repository')
            ->and($second)->toContain('for the second repository')->not->toContain('for the first repository');
    });

    it('leaves a workspace its own events when another workspace is drained first', function (): void {
        workspaceSink($this->one)->add([workspaceEvent('still waiting')]);

        workspaceHook(['workspace_roots' => [$this->two]]);

        expect(workspaceSink($this->one)->isEmpty())->toBeFalse();
    });

    it('takes the first folder when the chat has several', function (): void {
        workspaceSink($this->one)->add([workspaceEvent('in the first folder')]);

        expect(workspaceHook(['workspace_roots' => [$this->one, $this->two]]))->toContain('in the first folder');
    });
});

describe('a payload that names no folder', function (): void {
    it('falls back to CURSOR_PROJECT_DIR', function (): void {
        workspaceSink($this->two)->add([workspaceEvent('named by the environment')]);

        putenv('CURSOR_PROJECT_DIR='.$this->two);

        expect(workspaceHook(['loop_count' => 0]))->toContain('named by the environment');
    });

    it('falls back to CURSOR_PROJECT_DIR when stdin is empty or is not JSON', function (string $payload): void {
        workspaceSink($this->two)->add([workspaceEvent('named by the environment')]);

        putenv('CURSOR_PROJECT_DIR='.$this->two);

        expect(workspaceHook($payload))->toContain('named by the environment');
    })->with(['empty' => '', 'not json' => 'workspace_roots', 'a list that is empty' => '{"workspace_roots":[]}']);

    it('prefers the payload to the environment', function (): void {
        workspaceSink($this->one)->add([workspaceEvent('in the payload folder')]);
        workspaceSink($this->two)->add([workspaceEvent('in the environment folder')]);

        putenv('CURSOR_PROJECT_DIR='.$this->two);

        expect(workspaceHook(['workspace_roots' => [$this->one]]))->toContain('in the payload folder')->not->toContain('in the environment folder');
    });
});

/**
 * Run the stop hook's command with a stdin payload, and return [stdout, stderr, exit code].
 *
 * @return array{0: string, 1: string, 2: int}
 */
function workspaceHookStreams(string $payload, string $harness = 'cursor', ?string $project = null): array
{
    $stream = fopen('php://memory', 'r+') ?: throw new RuntimeException('Could not open a memory stream.');

    fwrite($stream, $payload);
    rewind($stream);

    app()->instance(HookPayload::class, new HookPayload($stream));

    $output = new TwoStreamOutput;
    $exit = Artisan::call('pending', array_filter(['--harness' => $harness, '--project' => $project]), $output);

    return [$output->stdout(), $output->stderr(), $exit];
}

describe('a hook that finds no folder (#386)', function (): void {
    it('says so on stderr, and changes neither stdout nor the exit code', function (string $payload): void {
        workspaceSink($this->one)->add([workspaceEvent('in a sink nobody reads here')]);

        [$stdout, $stderr, $exit] = workspaceHookStreams($payload);

        expect($stderr)->toContain('No workspace folder was found')->toContain('CURSOR_PROJECT_DIR')
            ->and($stdout)->toBeEmpty()
            ->and($exit)->toBe(0);
    })->with([
        'empty stdin' => '',
        'not JSON' => '{"workspace_roots":',
        'no roots' => '{"loop_count":0}',
        'only blank roots' => '{"workspace_roots":["", " "]}',
    ]);

    it('says it for a payload cut off at the read limit', function (): void {
        // Past the 1 MiB read limit the JSON is truncated mid-document, so it names no folder
        $padding = str_repeat('x', 1_048_576);

        [, $stderr] = workspaceHookStreams(json_encode(['padding' => $padding, 'workspace_roots' => [$this->one]], JSON_THROW_ON_ERROR));

        expect($stderr)->toContain('No workspace folder was found');
    });

    it('stays quiet when a folder is named, by the payload or by the environment', function (): void {
        [, $fromPayload] = workspaceHookStreams(json_encode(['workspace_roots' => [$this->one]], JSON_THROW_ON_ERROR));

        putenv('CURSOR_PROJECT_DIR='.$this->one);

        [, $fromEnvironment] = workspaceHookStreams('');

        expect($fromPayload)->toBeEmpty()->and($fromEnvironment)->toBeEmpty();
    });

    it('stays quiet for a named project and for another harness, whose keys ignore the folder', function (): void {
        [, $named] = workspaceHookStreams('', 'cursor', 'org/repo');

        putenv('ROBOT_COUNCIL_HARNESS=claude');

        [, $claude] = workspaceHookStreams('', 'claude');

        expect($named)->toBeEmpty()->and($claude)->toBeEmpty();
    });
});

describe('a workspace with several roots (#386)', function (): void {
    it('is read by the first root only, and never drains the sink of another root', function (): void {
        workspaceSink($this->one)->add([workspaceEvent('first root')]);
        workspaceSink($this->two)->add([workspaceEvent('second root')]);

        $printed = workspaceHook(['workspace_roots' => [$this->one, $this->two]]);

        expect($printed)->toContain('first root')->not->toContain('second root')
            ->and(workspaceSink($this->two)->isEmpty())->toBeFalse();
    });
});

describe('Claude Code', function (): void {
    it('resolves the sink it always did', function (): void {
        // The key before this change, written out: service, harness, an empty project, the checkout.
        // A folder outside any repository is its own checkout, so the checkout is the folder.
        $checkout = $this->one;

        if (PHP_OS_FAMILY === 'Windows') {
            $checkout = strtolower(str_replace('\\', '/', $checkout));
        }

        $key = substr(hash('sha256', implode("\0", [WORKSPACE_SERVICE, 'claude', '', $checkout])), 0, 32);

        expect(basename(workspaceSink($this->one, 'claude')->path()))->toBe($key.'.json');
    });

    it('reads CLAUDE_PROJECT_DIR and ignores a Cursor payload', function (): void {
        putenv('ROBOT_COUNCIL_HARNESS=claude');
        workspaceSink($this->one, 'claude')->add([workspaceEvent('for claude')]);
        workspaceSink($this->two, 'claude')->add([workspaceEvent('not for this one')]);

        putenv('CLAUDE_PROJECT_DIR='.$this->one);

        expect(workspaceHook(['workspace_roots' => [$this->two]], 'claude'))->toContain('for claude')->not->toContain('not for this one');
    });
});

describe('an upgrade', function (): void {
    it('delivers what an application-wide Cursor bridge left behind', function (): void {
        $shared = new PendingEvents(WORKSPACE_SERVICE, 'cursor', appWide: true);

        $shared->add([workspaceEvent('left by the old bridge')]);

        workspaceSink($this->one)->add([workspaceEvent('written by the new bridge')]);

        $printed = workspaceHook(['workspace_roots' => [$this->one]]);

        expect($printed)->toContain('left by the old bridge')->toContain('written by the new bridge');

        // Marked delivered, so the next hook removes them and prints nothing twice
        expect(workspaceHook(['workspace_roots' => [$this->one]]))->toBeEmpty()
            ->and($shared->isEmpty())->toBeTrue();
    });

    it('is the key an application-wide bridge always used', function (): void {
        // Written out from the old key: service, harness and project, with no checkout part.
        $old = substr(hash('sha256', implode("\0", [WORKSPACE_SERVICE, 'cursor', ''])), 0, 32);

        expect(basename(new PendingEvents(WORKSPACE_SERVICE, 'cursor', appWide: true)->path()))->toBe($old.'.json');
    });

    it('keeps a bridge launched from the home folder on that sink', function (): void {
        putenv('HOME='.$this->root.'/home');

        $fromHome = new PendingEvents(WORKSPACE_SERVICE, 'cursor', null, $this->root.'/home');

        expect($fromHome->path())->toBe(new PendingEvents(WORKSPACE_SERVICE, 'cursor', appWide: true)->path());

        $fromHome->add([workspaceEvent('from the user-level entry')]);

        expect(workspaceHook(['workspace_roots' => [$this->one]]))->toContain('from the user-level entry');
    });

    it('records no key for a bridge launched from the home folder, which has no workspace to name', function (): void {
        putenv('HOME='.$this->root.'/home');

        workspaceSink($this->root.'/home')->recordKey();

        expect(glob($this->root.'/state/robot-council/pending/*.key'))->toBeEmpty();

        // The control: a bridge in a workspace does record one
        workspaceSink($this->one)->recordKey();

        expect(glob($this->root.'/state/robot-council/pending/*.key'))->toHaveCount(1);
    });

    it('drains the old sink once when the hook itself is keyed to it', function (): void {
        putenv('HOME='.$this->root.'/home');

        $shared = new PendingEvents(WORKSPACE_SERVICE, 'cursor', appWide: true);
        $shared->add([workspaceEvent('only once')]);

        expect(substr_count(workspaceHook(['workspace_roots' => [$this->root.'/home']]), 'only once'))->toBe(1);
    });

    it('still delivers the workspace events when the old sink is unreadable', function (): void {
        $shared = new PendingEvents(WORKSPACE_SERVICE, 'cursor', appWide: true);
        $shared->add([workspaceEvent('unreadable soon')]);
        file_put_contents($shared->path(), '{ not json');

        workspaceSink($this->one)->add([workspaceEvent('from the workspace')]);

        expect(workspaceHook(['workspace_roots' => [$this->one]]))->toContain('from the workspace');
    });

    it('does not read the old sink for a named project, whose key never changed', function (): void {
        new PendingEvents(WORKSPACE_SERVICE, 'cursor', appWide: true)->add([workspaceEvent('the old shared sink')]);

        expect(Artisan::call('pending', ['--harness' => 'cursor', '--project' => 'org/repo']))->toBe(0)
            ->and(Artisan::output())->toBeEmpty();
    });
});

describe('the hook payload', function (): void {
    it('reads a folder past a byte-order mark', function (): void {
        expect(HookPayload::rootIn("\xEF\xBB\xBF".'{"workspace_roots":["'.str_replace('\\', '\\\\', $this->one).'"]}'))->toBe($this->one);
    });

    it('reads nothing from a payload with no usable folder', function (string $payload): void {
        expect(HookPayload::rootIn($payload))->toBeNull();
    })->with([
        'empty' => '',
        'not json' => '{"workspace_roots":',
        'not a list' => '{"workspace_roots":"/work"}',
        'a blank entry' => '{"workspace_roots":["", "  "]}',
        'a number' => '{"workspace_roots":[7]}',
        'a list at the top' => '["/work"]',
    ]);

    it('reads a Windows drive spelled with a leading slash', function (): void {
        if (PHP_OS_FAMILY !== 'Windows') {
            $this->markTestSkipped('Cursor spells a Windows drive this way; other systems keep the path as given.');
        }

        expect(HookPayload::rootIn('{"workspace_roots":["/c:/work/repo"]}'))->toBe('c:/work/repo');
    });

    it('skips a blank entry for the next folder', function (): void {
        expect(HookPayload::rootIn('{"workspace_roots":["", "/work/repo"]}'))->toBe('/work/repo');
    });
});
