<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Support\Credentials\Credential;
use App\Support\Credentials\CredentialStore;
use App\Support\Credentials\ReadsManyCredentials;

/**
 * A batching store that answers by POSITION, which is the shape a batch script naturally produces.
 *
 * **This fixture exists to be wrong in a specific way.** Hand a script N keys and it answers with
 * the results it found, in the order it found them; pairing those back up by index is the obvious
 * thing to do and is correct until something is missing. `getMany()` is required to **omit** a key
 * it could not read, so the first omission shifts every answer after it by one.
 *
 * The other fixtures cannot produce that. `BatchingProbeStore` and `TargetKeyedBatchingStore` both
 * build their result keyed by the key they are answering for, so no omission can misalign them --
 * a fixture cannot exercise a failure mode it has no mechanism for, which is why this one is
 * separate rather than another flag on those.
 *
 * What it costs is not "an empty answer". It is an answer that is **confidently wrong about which
 * harness holds which credential**, which is the thing `CredentialKey` exists to prevent.
 */
final class PositionalBatchingStore implements CredentialStore, ReadsManyCredentials
{
    /**
     * @var array<string, Credential> What this store holds, keyed by credential key.
     */
    public array $stored = [];

    /**
     * @var list<list<string>> Every `getMany()` call, so a miss is distinguishable from no call.
     */
    public array $batches = [];

    /**
     * @param  bool  $positional  Whether to pair answers back to keys by index, which is the defect.
     *                            False keys each entry by the key it answers for, which is correct
     *                            and is the control the test needs.
     */
    public function __construct(public bool $positional = true) {}

    public function available(): bool
    {
        return true;
    }

    public function describe(): string
    {
        return 'a positional batching probe store';
    }

    public function put(string $service, Credential $credential): void
    {
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

    /**
     * @param  list<string>  $services  The keys asked about.
     * @return array<string, Credential> Those that have one.
     */
    public function getMany(array $services): array
    {
        $this->batches[] = $services;

        // What a batch script hands back: the credentials it found, in the order it looked, with
        // nothing marking which key each one belongs to. A key it holds nothing for is simply
        // absent from this list, exactly as the contract requires of the map.
        $answers = [];

        foreach ($services as $service) {
            $credential = $this->stored[$service] ?? null;

            if ($credential instanceof Credential) {
                $answers[] = $this->positional
                    ? $credential
                    // The correct shape, for the control: carry the key alongside the value so the
                    // pairing cannot depend on how many keys came back.
                    : [$service, $credential];
            }
        }

        $found = [];

        foreach ($answers as $index => $answer) {
            if ($answer instanceof Credential) {
                // **The defect, in one line.** `$services[$index]` is the key at that POSITION in
                // the request, which is the key this credential belongs to only while nothing has
                // been omitted.
                if (isset($services[$index])) {
                    $found[$services[$index]] = $answer;
                }

                continue;
            }

            [$service, $credential] = $answer;

            $found[$service] = $credential;
        }

        return $found;
    }
}
