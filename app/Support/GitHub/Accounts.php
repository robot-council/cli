<?php

declare(strict_types=1);

namespace App\Support\GitHub;

use Illuminate\Http\Client\Factory;
use Throwable;

/**
 * Turns a GitHub login into the numeric account ID the access lists are checked against.
 *
 * **The service stores numeric IDs deliberately, and asking a person for one is still wrong.**
 * `robot-council.access.developers` holds IDs because a login can be renamed and then claimed by
 * somebody else, so a list of logins would silently admit whoever picked up an abandoned name. None
 * of that requires the person setting up a fleet to know, find, or type a number they have never
 * seen: they type the login they already know, and this resolves it once, at setup, in front of
 * them.
 *
 * Unauthenticated deliberately. The endpoint is public, the allowance is 60 an hour against an
 * address, and a handful of logins fits inside it -- and asking somebody to produce a GitHub token
 * before they can list three collaborators would cost more than it saves.
 */
final class Accounts
{
    /**
     * How long to wait on GitHub before giving up.
     */
    public const int TIMEOUT_SECONDS = 10;

    /**
     * @param  Factory  $http  The HTTP client.
     */
    public function __construct(private readonly Factory $http) {}

    /**
     * The numeric account ID for one login.
     *
     * @param  string  $login  The GitHub login.
     * @return int The numeric account ID.
     *
     * @throws AccountLookupFailed When there is no such account, or GitHub could not be asked.
     */
    public function id(string $login): int
    {
        try {
            $response = $this->http
                ->acceptJson()
                ->timeout(self::TIMEOUT_SECONDS)
                ->withHeaders(['User-Agent' => 'robot-council-cli'])
                ->get('https://api.github.com/users/'.rawurlencode($login));
        } catch (Throwable $throwable) {
            throw AccountLookupFailed::unavailable('Could not reach GitHub: '.$throwable->getMessage());
        }

        if ($response->status() === 404) {
            throw AccountLookupFailed::unknown($login);
        }

        if ($response->status() === 403 || $response->status() === 429) {
            // The unauthenticated allowance is per address and resets on the hour. Saying so is the
            // difference between a person waiting and a person retyping a login that was correct.
            throw AccountLookupFailed::unavailable(
                'GitHub is rate limiting this address. The unauthenticated allowance is 60 an hour.'
            );
        }

        if (! $response->successful()) {
            throw AccountLookupFailed::unavailable(sprintf('GitHub answered %d.', $response->status()));
        }

        $id = $response->json('id');

        if (! \is_int($id)) {
            throw AccountLookupFailed::unavailable('GitHub returned no numeric ID for that account.');
        }

        return $id;
    }
}
