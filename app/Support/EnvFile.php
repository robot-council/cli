<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * Sets values in a scaffolded application's `.env`.
 *
 * **Rewrites a key in place when it is already there, and appends when it is not.** Appending
 * unconditionally would leave two lines naming one key, and the file is read last-wins, so the
 * duplicate would work -- until somebody edited the first one and nothing happened.
 *
 * Values are written quoted when they contain anything a `.env` parser treats as a terminator, which
 * for a GitHub client secret is unlikely and for an application URL is not. Nothing here is echoed
 * back by the caller: one of the two values this writes is a secret.
 */
final class EnvFile
{
    /**
     * @param  string  $path  The file to edit.
     */
    public function __construct(private readonly string $path) {}

    /**
     * Set each key, rewriting it where it exists and appending it where it does not.
     *
     * @param  array<string, string>  $values  The keys to set, and what to set them to.
     *
     * @throws RuntimeException When the file cannot be read or written.
     */
    public function set(array $values): void
    {
        $contents = @file_get_contents($this->path);

        if ($contents === false) {
            throw new RuntimeException(sprintf('Could not read `%s`.', $this->path));
        }

        foreach ($values as $key => $value) {
            $line = $key.'='.$this->quoted($value);

            // Anchored to the start of a line, so `APP_URL` does not match `ROBOT_COUNCIL_APP_URL`,
            // and a commented-out key is left alone rather than silently uncommented
            $pattern = '/^'.preg_quote($key, '/').'=.*$/m';

            // **A callback, never a replacement string.** `preg_replace` reads `$1` and `\1` in its
            // replacement as back-references, and one of the values written here is a GitHub client
            // secret -- 40 characters this code does not choose. A secret containing `$1` would be
            // silently replaced by a captured group, writing a credential that never matches.
            $contents = preg_match($pattern, $contents) === 1
                ? (string) preg_replace_callback($pattern, static fn (): string => $line, $contents, 1)
                : rtrim($contents, "\n")."\n".$line."\n";
        }

        if (@file_put_contents($this->path, $contents) === false) {
            throw new RuntimeException(sprintf('Could not write `%s`.', $this->path));
        }
    }

    /**
     * One value, quoted when it needs to be.
     *
     * @param  string  $value  The value to write.
     * @return string The value as the file should hold it.
     */
    private function quoted(string $value): string
    {
        return preg_match('/[\s"\'#]/', $value) === 1
            ? '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"'
            : $value;
    }
}
