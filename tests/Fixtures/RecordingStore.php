<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Support\Credentials\Credential;
use App\Support\Credentials\CredentialStore;
use App\Support\Credentials\CredentialStoreFailed;

/**
 * A credential store that remembers rather than storing, so tests never touch a real Keychain.
 *
 * `available()` is always true, so it stands in for whichever store a machine would really pick.
 */
final class RecordingStore implements CredentialStore
{
    /**
     * @var array<string, Credential> What was stored, keyed by service.
     */
    public array $stored = [];

    /**
     * @param  bool  $fails  Whether `put()` should refuse, to exercise the failure path.
     */
    public function __construct(public bool $fails = false) {}

    public function available(): bool
    {
        return true;
    }

    public function describe(): string
    {
        return 'a test double';
    }

    public function put(string $service, Credential $credential): void
    {
        if ($this->fails) {
            throw new CredentialStoreFailed('The credential could not be stored.');
        }

        $this->stored[$service] = $credential;
    }

    public function get(string $service): ?Credential
    {
        return $this->stored[$service] ?? null;
    }

    public function forget(string $service): void
    {
        unset($this->stored[$service]);
    }
}
