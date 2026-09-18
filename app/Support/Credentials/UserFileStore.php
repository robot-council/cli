<?php

declare(strict_types=1);

namespace App\Support\Credentials;

/**
 * A file only the user can read, for machines with no credential store.
 *
 * **Always available, which is what makes it the fallback**: it is the last store tried, and it
 * never reports itself unavailable, so enrollment cannot end with nowhere to put the credential.
 *
 * Two properties matter more than where exactly the file lands:
 *
 * - **Mode `0600`.** Written by creating the file with `0600` before anything is put in it, rather
 *   than writing and then chmod-ing. The second order leaves a window, however short, where the
 *   token is on disk world-readable.
 * - **Outside any repository.** It goes under the user's home directory, never the working
 *   directory, because a file written beside a project gets committed. `XDG_CONFIG_HOME` is honored
 *   where set, since a developer who moved their config expects this to follow.
 */
final class UserFileStore implements CredentialStore
{
    /**
     * The directory name under the user's config directory.
     */
    public const string DIRECTORY = 'robot-council';

    public function available(): bool
    {
        // Deliberately unconditional. Every other store can be absent; this one is the reason
        // enrollment cannot fail for want of somewhere to write.
        return true;
    }

    public function describe(): string
    {
        return sprintf('`%s`, readable only by you', $this->path());
    }

    public function put(string $service, Credential $credential): void
    {
        $directory = \dirname($this->path());

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new CredentialStoreFailed(sprintf('Could not create `%s` to store the credential in.', $directory));
        }

        $all = $this->all();
        $all[$service] = $credential->reveal();

        $path = $this->path();

        // Narrowed BEFORE the token goes in. Writing first and chmod-ing after leaves the
        // credential world-readable for the width of that gap.
        $this->createNarrow($path);

        file_put_contents($path, json_encode($all, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        $stored = $this->get($service);

        if (! $stored instanceof Credential || ! $stored->equals($credential)) {
            throw new CredentialStoreFailed(sprintf('The credential could not be stored in `%s`.', $path));
        }

        // The mode is this store's only security property, and the read-back above proves the
        // CONTENT landed while saying nothing about who can read it. Checked separately, because
        // "stored" and "stored world-readable" are otherwise indistinguishable to the caller.
        $this->assertOnlyOwnerCanRead($path);
    }

    public function get(string $service): ?Credential
    {
        $value = $this->all()[$service] ?? null;

        return \is_string($value) ? new Credential($value) : null;
    }

    public function forget(string $service): void
    {
        $all = $this->all();

        unset($all[$service]);

        $path = $this->path();

        if ($all === []) {
            if (is_file($path)) {
                unlink($path);
            }

            return;
        }

        // The same order `put()` uses, and for the same reason: `file_put_contents` on a path that
        // does not exist creates it at the umask's mode -- measured 644 -- with the remaining
        // credentials already in it, and the chmod then narrows it afterwards. Touch first, narrow,
        // then write.
        $this->createNarrow($path);

        file_put_contents($path, json_encode($all, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        $this->assertOnlyOwnerCanRead($path);
    }

    /**
     * Where the file lives.
     *
     * Public so a test can assert on it, and so `describe()` can tell a developer where to look.
     */
    public function path(): string
    {
        $configured = getenv('XDG_CONFIG_HOME');

        if (\is_string($configured) && $configured !== '') {
            return rtrim($configured, '/\\').'/'.self::DIRECTORY.'/credentials.json';
        }

        // `HOME` is not set on Windows, and this is the one platform that ALWAYS lands here, since
        // `WindowsCredentialStore` reports itself unavailable. Without this the path resolved to
        // `/.config/…` -- the root of the current drive.
        $home = getenv('HOME');

        if (! \is_string($home) || $home === '') {
            $home = (string) getenv('USERPROFILE');
        }

        return rtrim($home, '/\\').'/.config/'.self::DIRECTORY.'/credentials.json';
    }

    /**
     * Create the file, narrowed, before anything is written into it.
     *
     * **`fopen(..., 'c')` does NOT create at 0600.** Measured under umask 022: the file exists at
     * 644 the instant it is created, and only the chmod that follows narrows it. The invariant that
     * matters is therefore *narrow before write*, not *create narrow* -- and a maintainer who
     * believed the latter could reorder these two lines without noticing.
     *
     * @param  string  $path  The file to create.
     *
     * @throws CredentialStoreFailed When it cannot be created or narrowed.
     */
    private function createNarrow(string $path): void
    {
        $handle = @fopen($path, 'c');

        if ($handle === false) {
            throw new CredentialStoreFailed(sprintf('Could not open `%s` to store the credential.', $path));
        }

        fclose($handle);

        if (! @chmod($path, 0600)) {
            throw new CredentialStoreFailed(sprintf('Could not restrict `%s` to its owner.', $path));
        }
    }

    /**
     * Refuse to leave a credential in a file somebody else can read.
     *
     * Skipped on Windows, where `chmod` only toggles the read-only bit and the mode it reports says
     * nothing about who may read the file. That is a real gap on the one platform that always uses
     * this store, and it is why #7 exists.
     *
     * @param  string  $path  The file to check.
     *
     * @throws CredentialStoreFailed When anyone but the owner can read it.
     */
    private function assertOnlyOwnerCanRead(string $path): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            return;
        }

        $mode = fileperms($path);

        if ($mode === false || ($mode & 0077) !== 0) {
            throw new CredentialStoreFailed(sprintf('`%s` is readable by more than its owner.', $path));
        }
    }

    /**
     * Every stored credential, keyed by service.
     *
     * @return array<string, mixed> What the file holds, or nothing when it does not exist or cannot
     *                              be parsed. A corrupt file reads as empty rather than throwing,
     *                              so a developer can re-enroll rather than hand-edit JSON.
     */
    private function all(): array
    {
        $path = $this->path();

        if (! is_file($path)) {
            return [];
        }

        $contents = file_get_contents($path);

        if ($contents === false || $contents === '') {
            return [];
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (! \is_array($decoded)) {
            return [];
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
