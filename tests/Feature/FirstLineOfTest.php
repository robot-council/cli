<?php

declare(strict_types=1);

/**
 * `firstLineOf()`, the helper that turns a dead child's stderr into a failure message.
 *
 * **The helper is an instrument, and a broken instrument reports a clean run.** Every assertion
 * that calls it hands the result to `sprintf` eagerly, so a fatal inside it would turn the suite
 * red -- but a wrong *answer* would not. It would surface the wrong line, or a mangled one, only
 * during the CI failure it exists to explain, which is the one moment nobody can re-run it. So
 * these cases pin what it returns, rather than that it returns.
 *
 * Written for `robot-council/cli#76`, where two instances reported `255` and nothing else.
 *
 * @command  vendor/bin/pest --compact tests/Feature/FirstLineOfTest.php
 */
it('returns the line that names the fault, not the blank line above it', function (): void {
    // The shape PHP writes for an uncaught exception: a leading blank line, the `Uncaught` line,
    // then frames. The first line of the string is not the first line of content, and #76's whole
    // point is that the `Uncaught` line is the one worth having.
    $stderr = "\nPHP Fatal error:  Uncaught App\Support\Credentials\CredentialStoreFailed: powershell.exe exited 1\nStack trace:\n#0 {main}\n";

    expect(firstLineOf($stderr))->toStartWith('PHP Fatal error:')
        ->and(firstLineOf($stderr))->toContain('CredentialStoreFailed')
        ->and(firstLineOf($stderr))->not->toContain('Stack trace');
});

it('splits the way a Windows child writes its lines', function (string $label, string $stderr): void {
    expect(firstLineOf($stderr))->toBe('first');
})->with([
    // A split on "\n" alone leaves a trailing carriage return here, which `trim()` would then hide
    // -- so this case passes either way and is not the discriminating one.
    ['carriage return and line feed', "first\r\nsecond\r\n"],
    // This is. `\R` matches a lone carriage return; `explode("\n", ...)` returns the whole string
    // as one line, and the assertion fails with both halves in it.
    ['carriage return alone', "first\rsecond"],
    ['line feed alone', "first\nsecond"],
]);

it('caps a runaway line at 300 characters and marks that it did', function (int $length, bool $capped): void {
    // The literals are deliberate. A boundary derived from the helper's own number moves with it,
    // so a cap quietly lowered to 80 would keep passing -- the lesson `robot-council/cli#63` paid
    // for on the Credential Manager ceilings.
    $result = firstLineOf(str_repeat('a', $length));

    expect($result)->toHaveLength($capped ? 301 : $length)
        ->and(str_ends_with($result, '…'))->toBe($capped);
})->with([
    'at the ceiling' => [300, false],
    'one character over' => [301, true],
]);

it('does not claim a truncation it did not make', function (): void {
    // 121 three-byte characters: 363 bytes, and 121 characters -- over a byte guard and well under
    // a character one. **This is the only input here that catches a guard and a cut disagreeing**,
    // which a hand mutation confirmed: swapping `mb_strlen()` for `strlen()` in the guard survived
    // every other test in this file. Such a guard fires on a line the cut then returns whole, so
    // the message ends in an ellipsis announcing a truncation that never happened.
    $line = str_repeat("\u{6f22}", 121);

    expect(\strlen($line))->toBeGreaterThan(300)
        ->and(mb_strlen($line))->toBeLessThan(300)
        ->and(firstLineOf($line))->toBe($line);
});

it('cuts on a character boundary, so what it produces is still valid UTF-8', function (): void {
    // One ASCII byte and then 3-byte characters, so byte 300 cannot fall on a character boundary.
    $line = 'a'.str_repeat("\u{6f22}", 400);

    expect(mb_check_encoding(firstLineOf($line), 'UTF-8'))->toBeTrue()
        // **The control.** A byte-wise cut of this same input is invalid UTF-8, so the assertion
        // above is about where the cut lands and not about the input being harmless either way.
        ->and(mb_check_encoding(substr($line, 0, 300), 'UTF-8'))->toBeFalse()
        // And the control's own control: on ASCII the two cuts agree, which is why a byte cap
        // survived review in the first place.
        ->and(substr(str_repeat('a', 400), 0, 300))->toBe(mb_substr(str_repeat('a', 400), 0, 300));
});

it('says so when there was nothing on stderr', function (string $label, string $stderr): void {
    // A child that died without writing anything is a different fact from one whose message was
    // lost, and the message has to tell them apart. An empty string in the sentence reads as the
    // latter.
    expect(firstLineOf($stderr))->toBe('(nothing on stderr)');
})->with([
    ['empty', ''],
    ['line feeds only', "\n\n"],
    ['whitespace only', "  \r\n\t\r\n"],
]);
