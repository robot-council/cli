<?php

declare(strict_types=1);

/**
 * The credential must survive being rendered by an exception handler.
 *
 * **This file exists because a green suite could not see the defect it covers.** Before
 * `Credential`, an ordinary failure inside a store printed the token to stdout: PHP captures
 * function arguments into exception traces when `zend.exception_ignore_args` is `0` -- its built-in
 * default -- and Laravel Zero renders an uncaught exception through Collision, whose
 * `ArgumentFormatter` prints string arguments up to 1000 characters.
 *
 * Measured on 2026-09-18: an unwritable config directory made `mkdir()` raise, Laravel's
 * `HandleExceptions` turned that warning into an `ErrorException`, it escaped a
 * `catch (CredentialStoreFailed)`, and Collision rendered
 * `UserFileStore::put("https://…", "rcouncil_1|…")`. The control was the same probe under
 * `zend.exception_ignore_args=1`, which rendered the frame with no arguments at all -- which is how
 * the mechanism was identified rather than guessed.
 *
 * `ArgumentFormatter` prints an object as `Object(ClassName)`, so carrying the token in one removes
 * it from every frame on every path, including paths nobody has thought of.
 *
 * @command  vendor/bin/pest --compact tests/Feature/CredentialRenderingTest.php
 */

use App\Support\Credentials\Credential;
use NunoMaduro\Collision\Writer;
use Symfony\Component\Console\Output\BufferedOutput;
use Whoops\Exception\Inspector;

const CANARY = 'rcouncil_1|CANARY-TOKEN-0123456789abcdef';

/**
 * Render a throwable the way the application would, and return what reached the terminal.
 */
function rendered(Throwable $throwable): string
{
    $output = new BufferedOutput;

    // Collision's `Writer` is marked `@internal`, and it is used here deliberately: the defect this
    // file covers is a property of how Collision renders, so rendering through anything else would
    // test a different thing. If a future Collision makes this unusable, that is a signal worth a
    // failing test rather than a silent gap.
    /** @phpstan-ignore new.internalClass, method.internalClass, method.internalClass */
    new Writer(null, $output)->write(new Inspector($throwable));

    return $output->fetch();
}

it('keeps the credential out of a rendered stack trace', function (): void {
    $leak = function (Credential $credential): never {
        throw new RuntimeException('something went wrong deep inside a store');
    };

    $trace = rendered(
        (function () use ($leak): Throwable {
            try {
                $leak(new Credential(CANARY));
            } catch (Throwable $throwable) {
                return $throwable;
            }
        })()
    );

    expect($trace)->not->toContain(CANARY);
});

it('would have leaked a bare string, which is why the object exists', function (): void {
    // The control. Without it, the assertion above passes on a machine where argument capture is
    // off for some other reason, and would keep passing if `Credential` were deleted.
    $leak = function (string $token): never {
        throw new RuntimeException('something went wrong deep inside a store');
    };

    $trace = rendered(
        (function () use ($leak): Throwable {
            try {
                $leak(CANARY);
            } catch (Throwable $throwable) {
                return $throwable;
            }
        })()
    );

    // If this ever stops containing the canary, argument capture is disabled on this machine and
    // the test above has stopped proving anything -- so it is skipped rather than silently vacuous.
    if (! str_contains($trace, CANARY)) {
        $this->markTestSkipped('zend.exception_ignore_args is on, so nothing can leak this way here.');
    }

    expect($trace)->toContain(CANARY);
});

it('shows nothing to a dumper either', function (): void {
    // `dd()` and `var_dump()` are the other way a secret reaches a terminal by accident
    ob_start();
    var_dump(new Credential(CANARY));
    $dumped = (string) ob_get_clean();

    expect($dumped)->not->toContain(CANARY)
        ->toContain('redacted');
});

it('cannot be interpolated into a string by accident', function (): void {
    // No `__toString()`, so `"$credential"` is a TypeError rather than a silent disclosure. PHPStan
    // proves that statically, which is the point -- the suppression is what lets the test assert the
    // runtime behaviour a host without static analysis would hit.
    /** @phpstan-ignore binaryOp.invalid, return.type */
    expect(fn (): string => 'token: '.new Credential(CANARY))->toThrow(Error::class);
});

it('compares without either side handling the raw value', function (): void {
    expect(new Credential(CANARY)->equals(new Credential(CANARY)))->toBeTrue()
        ->and(new Credential(CANARY)->equals(new Credential('something-else')))->toBeFalse();
});
