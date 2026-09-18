<?php

declare(strict_types=1);

/**
 * What this machine calls itself, reduced to what `robot-council/core` will store.
 *
 * `core` is the authority on both bounds. A value this command line sends that the service refuses
 * is a bug here: a developer should not have to discover that their hostname contains a character
 * an unfamiliar service dislikes.
 *
 * @command  vendor/bin/pest --compact tests/Feature/MachineIdentityTest.php
 */

use App\Support\MachineIdentity;

it('reduces a harness to the charset the service accepts', function (string $given, ?string $expected): void {
    expect(MachineIdentity::harness($given))->toBe($expected);
})->with([
    'already fine' => ['claude', 'claude'],
    'hyphenated, as the detector reports some' => ['augment-cli', 'augment-cli'],
    'upper case becomes the same harness, not a second one' => ['Claude', 'claude'],
    'a space is dropped rather than refused' => ['Augment CLI', 'augmentcli'],
    'markup cannot survive' => ['<script>', 'script'],
    'nothing usable left' => ['!!!', null],
    'empty' => ['', null],
]);

it('truncates a harness to the column that holds it', function (): void {
    $long = MachineIdentity::harness(str_repeat('a', MachineIdentity::MAX_HARNESS + 20));

    expect($long)->toHaveLength(MachineIdentity::MAX_HARNESS);
});

it('reduces a machine label to the charset the service accepts', function (string $given, string $expected): void {
    expect(MachineIdentity::label($given))->toBe($expected);
})->with([
    'already fine' => ['workbench-01', 'workbench-01'],
    'keeps case, dots and underscores, unlike a harness' => ['Workbench_01.local2', 'Workbench_01.local2'],
    'drops a space' => ['Josh laptop', 'Joshlaptop'],
    'drops markup' => ['<script>alert(1)</script>', 'scriptalert1script'],
    'nothing usable left falls back rather than failing' => ['!!!', 'unknown-machine'],
]);

it('strips the .local every macOS hostname carries', function (): void {
    // Every machine on the fleet would otherwise be labelled `.local`, which distinguishes nothing
    expect(MachineIdentity::label('MacBook-Pro-2.local'))->toBe('MacBook-Pro-2');
});

it('truncates a label to the column that holds it', function (): void {
    expect(MachineIdentity::label(str_repeat('b', MachineIdentity::MAX_LABEL + 20)))
        ->toHaveLength(MachineIdentity::MAX_LABEL);
});

it('agrees with the bounds core actually enforces', function (): void {
    // Written out rather than imported, because `core` is a separate package this one does not
    // depend on. If these drift, enrollment starts failing at the service with a 422 that says
    // nothing about which field, so the numbers are pinned here deliberately.
    expect(MachineIdentity::MAX_HARNESS)->toBe(32)
        ->and(MachineIdentity::MAX_LABEL)->toBe(64);

    // And what it produces satisfies core's own patterns
    expect(MachineIdentity::harness('Claude Code'))->toMatch('/^[a-z0-9-]+$/')
        ->and(MachineIdentity::label('Josh MacBook Pro.local'))->toMatch('/^[A-Za-z0-9._-]+$/');
});
