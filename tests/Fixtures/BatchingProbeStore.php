<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Support\Credentials\Credential;
use App\Support\Credentials\CredentialStore;
use App\Support\Credentials\CredentialStoreFailed;
use App\Support\Credentials\ReadsManyCredentials;

/**
 * A store that answers several keys at once, standing in for `WindowsCredentialStore` under #90.
 *
 * **This fixture is what makes the capability more than an assertion.** `Credentials` branches on
 * `instanceof ReadsManyCredentials`, and no store in the application declares it yet, so without a
 * double the batch path would ship untested and #90 would be the first thing ever to run it.
 *
 * It wraps a `ProbeStore` rather than extending one, because the architecture presets forbid a
 * non-final class.
 */
final class BatchingProbeStore implements CredentialStore, ReadsManyCredentials
{
    /**
     * @var list<list<string>> Every `getMany()` call, in order.
     *
     * **A list of calls rather than a counter**, so a batch that found nothing is distinguishable
     * from one that never ran. #31's first prototype reported `442ms, found 0` having inserted
     * nothing at all, and the two look identical from the result alone.
     */
    public array $batches = [];

    /**
     * @param  ProbeStore  $inner  Where the credentials actually live.
     * @param  bool  $refuses  Whether `getMany()` raises wholesale, as a store that cannot run at all would.
     * @param  bool  $reversed  Whether `getMany()` answers in an order other than the one asked for.
     */
    public function __construct(
        public readonly ProbeStore $inner = new ProbeStore,
        public bool $refuses = false,
        public bool $reversed = false,
    ) {}

    public function available(): bool
    {
        return true;
    }

    public function describe(): string
    {
        return 'a batching probe store';
    }

    public function put(string $service, Credential $credential): void
    {
        $this->inner->put($service, $credential);
    }

    public function get(string $service): ?Credential
    {
        return $this->inner->get($service);
    }

    public function forget(string $service): void
    {
        $this->inner->forget($service);
    }

    /**
     * @param  list<string>  $services  The keys to read.
     * @return array<string, Credential> Those that have one.
     */
    public function getMany(array $services): array
    {
        $this->batches[] = $services;

        if ($this->refuses) {
            throw new CredentialStoreFailed('The batching probe store cannot run.');
        }

        $found = [];

        foreach ($this->reversed ? array_reverse($services) : $services as $service) {
            // **Honors the contract the interface states**: a key it cannot read is omitted, not
            // raised for, so one bad key does not cost the others.
            if (\in_array($service, $this->inner->unreadable, true)) {
                continue;
            }

            $credential = $this->inner->stored[$service] ?? null;

            if ($credential instanceof Credential) {
                $found[$service] = $credential;
            }
        }

        return $found;
    }
}
