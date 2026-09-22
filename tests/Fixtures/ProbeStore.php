<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Support\Credentials\Credential;
use App\Support\Credentials\CredentialStore;
use App\Support\Credentials\CredentialStoreFailed;

/**
 * A store that records what it was asked for, and can refuse specific keys.
 *
 * `RecordingStore` covers the storing half; this one exists for the reading half, where the
 * question is **which keys were asked about and how many times** rather than what came back. It
 * declares no batching capability, so it exercises the looping path in `Credentials::readMany()`.
 *
 * **`$unreadable` is the point of it.** A store whose mechanism is broken for one key raises for
 * that key alone, and `storedAmong()` is documented to answer a lower bound rather than abort --
 * a property that cannot be shown with a store that either works or does not.
 */
final class ProbeStore implements CredentialStore
{
    /**
     * @var array<string, Credential> What this store holds, keyed by credential key.
     */
    public array $stored = [];

    /**
     * @var list<string> Every key `get()` was called with, in order, including repeats.
     */
    public array $reads = [];

    /**
     * @var list<string> Keys whose read raises, standing in for a mechanism broken for one entry.
     */
    public array $unreadable = [];

    public function available(): bool
    {
        return true;
    }

    public function describe(): string
    {
        return 'a probe store';
    }

    public function put(string $service, Credential $credential): void
    {
        $this->stored[$service] = $credential;
    }

    public function get(string $service): ?Credential
    {
        $this->reads[] = $service;

        if (\in_array($service, $this->unreadable, true)) {
            throw new CredentialStoreFailed('The probe store refuses '.$service.'.');
        }

        return $this->stored[$service] ?? null;
    }

    public function forget(string $service): void
    {
        unset($this->stored[$service]);
    }
}
