<?php

declare(strict_types=1);

namespace Tests\Fixtures;

/**
 * A stream wrapper that opens, locks and truncates, and then writes nothing.
 *
 * Registered under a scheme by the test that needs it. A write that fails is the one branch of
 * `StopRecord::append()` that a real file cannot reach on every platform: a full disk is not
 * available to a test, and a null device reports a warning instead of a short write on Windows. The
 * directory check passes through `url_stat()`, so the path can be a plain URL under the scheme.
 */
final class FailingWriteStream
{
    /**
     * @var resource|null Set by PHP when a stream is opened on this wrapper.
     */
    public $context;

    public function stream_open(): bool
    {
        return true;
    }

    public function stream_lock(): bool
    {
        return true;
    }

    public function stream_seek(): bool
    {
        return true;
    }

    public function stream_tell(): int
    {
        return 0;
    }

    public function stream_read(): string
    {
        return '';
    }

    public function stream_eof(): bool
    {
        return true;
    }

    public function stream_truncate(): bool
    {
        return true;
    }

    /**
     * Accept nothing, which `fwrite()` reports as a write of zero bytes.
     */
    public function stream_write(): int
    {
        return 0;
    }

    public function stream_flush(): bool
    {
        return true;
    }

    /**
     * @return array<string, int>
     */
    public function stream_stat(): array
    {
        return ['size' => 0];
    }

    /**
     * Report every path as a directory, so the check that the folder exists passes.
     *
     * @return array<string, int>
     */
    public function url_stat(): array
    {
        return ['mode' => 0040000, 'size' => 0];
    }
}
