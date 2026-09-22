<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Support\Credentials\Credential;
use App\Support\Credentials\CredentialStore;
use App\Support\Credentials\ReadsManyCredentials;
use App\Support\Credentials\WindowsCredentialTarget;

/**
 * A batching store that files credentials under a name of its own, as the Windows one does.
 *
 * **`BatchingProbeStore` cannot catch the mistake this exists for.** It stores under the key it is
 * handed and answers with that same key, so its input and output spaces are identical and the
 * round trip is a no-op -- a batch implementation that answered in its own addressing space would
 * pass every test written against it.
 *
 * This one does not. It files under `WindowsCredentialTarget::for()`, the real transformation
 * `WindowsCredentialStore` applies, so the key it is asked about and the name it holds the
 * credential under are different strings by construction. Translating back is then a step that has
 * to actually happen, which is what makes the tests using it mean something for #90.
 */
final class TargetKeyedBatchingStore implements CredentialStore, ReadsManyCredentials
{
    /**
     * @var array<string, Credential> Credentials, keyed by TARGET name rather than by credential key.
     */
    public array $stored = [];

    /**
     * @var list<list<string>> Every `getMany()` call, so a miss is distinguishable from no call.
     */
    public array $batches = [];

    /**
     * @param  bool  $answersInStoreSpace  Whether to answer with target names, which is the defect
     *                                     this fixture exists to make visible. A real store that
     *                                     did this would be silently useless.
     */
    public function __construct(public bool $answersInStoreSpace = false) {}

    public function available(): bool
    {
        return true;
    }

    public function describe(): string
    {
        return 'a target-keyed batching probe store';
    }

    public function put(string $service, Credential $credential): void
    {
        $this->stored[WindowsCredentialTarget::for($service)] = $credential;
    }

    public function get(string $service): ?Credential
    {
        return $this->stored[WindowsCredentialTarget::for($service)] ?? null;
    }

    public function forget(string $service): void
    {
        unset($this->stored[WindowsCredentialTarget::for($service)]);
    }

    /**
     * @param  list<string>  $services  The credential keys asked about.
     * @return array<string, Credential> Those that have one.
     */
    public function getMany(array $services): array
    {
        $this->batches[] = $services;

        $found = [];

        foreach ($services as $service) {
            $target = WindowsCredentialTarget::for($service);

            $credential = $this->stored[$target] ?? null;

            if ($credential instanceof Credential) {
                // **The translation back is the whole point.** Keyed by `$target`, this store is
                // answering a question nobody asked, in a vocabulary the caller cannot read.
                $found[$this->answersInStoreSpace ? $target : $service] = $credential;
            }
        }

        return $found;
    }
}
