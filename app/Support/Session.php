<?php

declare(strict_types=1);

namespace App\Support;

use App\Support\Credentials\Credential;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use RuntimeException;

/**
 * One agent session against the coordination service, from start to end.
 *
 * **Ending is not optional, and not best-effort.** A session the service still believes is alive
 * holds whatever it claimed: `core` releases a session's tasks and locks when it ends, and waits
 * for the presence sweep otherwise. So a command that starts a session and exits without ending it
 * leaves the fleet holding work nobody is doing, for as long as the stale threshold.
 *
 * That is why `end()` is called from a `finally` rather than after the work: the request failing is
 * exactly the case where leaving a session open costs the most.
 */
final class Session
{
    /**
     * @param  Factory  $http  The HTTP client.
     * @param  string  $service  The service's base URL, with no trailing slash.
     * @param  Credential  $installation  The credential enrollment stored.
     */
    public function __construct(
        private readonly Factory $http,
        private readonly string $service,
        private readonly Credential $installation,
    ) {}

    /**
     * The session's own id, once started.
     */
    private ?int $id = null;

    /**
     * The session token, which is what every call inside the session carries.
     */
    private ?Credential $token = null;

    /**
     * Where the feed stood when this session started.
     *
     * Returned by `core` since #61, so an agent reaches current events in one request rather than
     * walking the whole feed. A one-shot call has nowhere to carry it, and `robot-council/core#86`
     * covers making it recoverable -- it is kept here so the bridge in #5 can use it.
     */
    private ?int $feedCursor = null;

    /**
     * Start the session.
     *
     * @param  string|null  $projectId  The repository or workspace, when the caller names one.
     *
     * @throws RuntimeException When the service refuses.
     */
    public function start(?string $projectId = null): void
    {
        $response = $this->http
            ->acceptJson()
            ->asJson()
            ->withToken($this->installation->reveal())
            ->post($this->service.'/robot-council/api/sessions', array_filter([
                'project_id' => $projectId,
            ]));

        if ($response->status() === 401) {
            throw new RuntimeException('This machine is not enrolled, or its credential was revoked. Run `robot-council enroll`.');
        }

        if (! $response->successful()) {
            throw new RuntimeException(sprintf('The service refused to start a session (HTTP %d).', $response->status()));
        }

        $body = $response->json();

        if (! \is_array($body) || ! \is_int($body['session_id'] ?? null) || ! \is_string($body['token'] ?? null)) {
            throw new RuntimeException('The service started a session but did not describe it usably.');
        }

        $this->id = $body['session_id'];
        $this->token = new Credential($body['token']);
        $this->feedCursor = \is_int($body['feed_cursor'] ?? null) ? $body['feed_cursor'] : null;
    }

    /**
     * A request carrying this session's token.
     *
     * @throws RuntimeException When the session has not been started.
     */
    public function request(): PendingRequest
    {
        if (! $this->token instanceof Credential) {
            throw new RuntimeException('The session has not been started.');
        }

        return $this->http->acceptJson()->withToken($this->token->reveal());
    }

    /**
     * End the session, releasing whatever it held.
     *
     * **Never throws.** It is called from a `finally`, so an exception here would replace whatever
     * actually went wrong with a failure to clean up after it -- and the caller would lose the real
     * reason. A session that could not be ended is swept by `core` on its presence threshold.
     */
    public function end(): void
    {
        if ($this->id === null || ! $this->token instanceof Credential) {
            return;
        }

        try {
            $this->http
                ->acceptJson()
                ->withToken($this->installation->reveal())
                ->delete($this->service.'/robot-council/api/sessions/'.$this->id);
        } catch (\Throwable) {
            // Deliberately swallowed. See above.
        }

        $this->id = null;
        $this->token = null;
    }

    /**
     * Where the feed stood when this session started, when the service said.
     */
    public function feedCursor(): ?int
    {
        return $this->feedCursor;
    }

    /**
     * This session's id, once started.
     */
    public function id(): ?int
    {
        return $this->id;
    }
}
