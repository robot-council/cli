<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use LogicException;
use RuntimeException;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;

/**
 * A console output with two separate streams, so a test can tell stdout from stderr.
 *
 * **`Artisan::output()` cannot answer the question `robot-council/cli#205` is about.**
 * `Artisan::call()` without an output builds a `BufferedOutput`, which is one buffer with no error
 * stream -- so a diagnostic written to stdout and one written to stderr arrive as the same string,
 * and an assertion over it passes either way. That is what let the status line sit beside the
 * response body on stdout with a test named `with the status off stdout` passing over it.
 *
 * It also decides which branch of a command's `diagnostic()` runs: the `ConsoleOutputInterface`
 * check is what routes a message to `getErrorOutput()`, and under a `BufferedOutput` the fallback
 * writes to the process's real `php://stderr` instead, where the test cannot see it either.
 *
 * Both streams are `php://memory` and undecorated, so what is read back is the bytes written with
 * no ANSI escapes to strip.
 */
final class TwoStreamOutput extends StreamOutput implements ConsoleOutputInterface
{
    private OutputInterface $errorOutput;

    public function __construct()
    {
        parent::__construct($this->memory(), self::VERBOSITY_NORMAL, false);

        $this->errorOutput = new StreamOutput($this->memory(), self::VERBOSITY_NORMAL, false);
    }

    public function getErrorOutput(): OutputInterface
    {
        return $this->errorOutput;
    }

    public function setErrorOutput(OutputInterface $error): void
    {
        $this->errorOutput = $error;
    }

    /**
     * Never called by these tests, and it cannot be built without a real console.
     */
    public function section(): ConsoleSectionOutput
    {
        throw new LogicException('TwoStreamOutput has no sections.');
    }

    /**
     * Everything written to stdout, byte for byte.
     */
    public function stdout(): string
    {
        return $this->contentsOf($this->getStream());
    }

    /**
     * Everything written to stderr, byte for byte.
     */
    public function stderr(): string
    {
        if (! $this->errorOutput instanceof StreamOutput) {
            throw new RuntimeException('The error output was replaced with one that has no stream.');
        }

        return $this->contentsOf($this->errorOutput->getStream());
    }

    /**
     * @return resource
     */
    private function memory()
    {
        $stream = fopen('php://memory', 'w+');

        if (! \is_resource($stream)) {
            throw new RuntimeException('Could not open a memory stream.');
        }

        return $stream;
    }

    /**
     * @param  resource  $stream  The stream to read whole.
     */
    private function contentsOf($stream): string
    {
        rewind($stream);

        return (string) stream_get_contents($stream);
    }
}
