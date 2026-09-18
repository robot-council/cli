<?php

declare(strict_types=1);

/**
 * What a service is allowed to write to a developer's terminal.
 *
 * Every string the enrollment flow prints -- the user code, the verification URL, an error -- comes
 * over the wire. A hostile or compromised service that can put escape sequences in any of them can
 * redraw the line so the code a person reads is not the code they approve.
 *
 * @command  vendor/bin/pest --compact tests/Feature/TerminalTest.php
 */

use App\Support\Terminal;

it('removes escape sequences a service could use to redraw the line', function (string $given, string $expected): void {
    expect(Terminal::safe($given))->toBe($expected);
})->with([
    'an ordinary URL survives untouched' => [
        'https://fleet.example.test/robot-council/enroll?x=1&y=2',
        'https://fleet.example.test/robot-council/enroll?x=1&y=2',
    ],
    'an ordinary code survives untouched' => ['HFTGPSTW', 'HFTGPSTW'],
    'a carriage return, which alone can overwrite a line already read' => ["HFTGPSTW\rFAKE1234", 'HFTGPSTWFAKE1234'],
    'an ANSI colour sequence' => ["\e[31mHFTGPSTW\e[0m", '[31mHFTGPSTW[0m'],
    'a cursor-up sequence' => ["HFTGPSTW\e[1A\e[2K", 'HFTGPSTW[1A[2K'],
    'a newline, which could fabricate a second line of output' => ["HFTGPSTW\nThis machine is enrolled.", 'HFTGPSTWThis machine is enrolled.'],
    'a null byte' => ["HFTG\0PSTW", 'HFTGPSTW'],
    'nothing printable left' => ["\e\e\e", '[empty]'],
]);

it('bounds a value a service tries to fill the screen with', function (): void {
    $long = str_repeat('a', Terminal::MAX + 500);

    expect(Terminal::safe($long))->toHaveLength(Terminal::MAX + 1);
});

it('refuses to print a value that is not valid text', function (): void {
    // `preg_replace` answers null on invalid UTF-8, which is itself a reason not to print it
    expect(Terminal::safe("\xC3\x28 broken"))->toBe('[unprintable]');
});

it('leaves the ESC character nowhere in its output', function (): void {
    // The property that matters, asserted directly rather than inferred from the cases above
    foreach (["\e[31m", "a\e[2Jb", "\e]0;title\x07"] as $hostile) {
        expect(Terminal::safe($hostile))->not->toContain("\e");
    }
});
