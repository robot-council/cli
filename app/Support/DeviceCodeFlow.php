<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Client\Factory;
use RuntimeException;

/**
 * The device-code flow from `robot-council/core#22`, as this machine drives it.
 *
 * The shape is device-code *shaped* rather than RFC 8628 conformant, which `core` decided in #40:
 * the token endpoint takes a `code_verifier` because this flow is PKCE-backed, and every expiry is
 * an `expires_in` in seconds rather than an instant, so a machine whose clock is wrong still
 * behaves.
 *
 * **The verifier never leaves memory.** Only its SHA-256 goes to the service, and the row the
 * service stores holds only a hash of the device code -- so neither side keeps anything that could
 * replay this enrollment.
 */
final class DeviceCodeFlow
{
    /**
     * How long to keep polling before giving up, whatever the service said.
     *
     * A backstop, not the schedule: the service returns its own `expires_in`, and this only bounds
     * the case where it returns something unreasonable.
     */
    public const int MAX_WAIT_SECONDS = 900;

    /**
     * @param  Factory  $http  The HTTP client.
     * @param  string  $service  The service's base URL, with no trailing slash.
     */
    public function __construct(
        private readonly Factory $http,
        private readonly string $service,
    ) {}

    /**
     * Ask the service to start an enrollment.
     *
     * @param  string  $harness  What this machine's agent software calls itself.
     * @param  string  $machineLabel  What this machine calls itself.
     * @param  list<string>  $abilities  The abilities being requested.
     * @param  string  $challenge  The SHA-256 of the verifier, which stays with the caller.
     * @return array<string, mixed> The service's response.
     *
     * @throws RuntimeException When the service refuses the request.
     */
    public function request(string $harness, string $machineLabel, array $abilities, string $challenge): array
    {
        $response = $this->http
            ->acceptJson()
            ->asJson()
            ->post($this->service.'/robot-council/api/device/code', [
                'harness' => $harness,
                'machine_label' => $machineLabel,
                'requested_abilities' => $abilities,
                'code_challenge' => $challenge,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException($this->explain($response->status(), $response->json()));
        }

        return $this->decode($response->json());
    }

    /**
     * Exchange an approved device code for an installation credential.
     *
     * Returns null while the developer has not decided yet, which is `authorization_pending` and is
     * the flow working rather than a failure -- the caller polls on null.
     *
     * @param  string  $deviceCode  The device code this machine was issued.
     * @param  string  $verifier  The verifier whose hash was sent as the challenge.
     * @return array<string, mixed>|null The credential, or null while the decision is outstanding.
     *
     * @throws RuntimeException When the code was denied, expired, or is otherwise dead.
     */
    public function exchange(string $deviceCode, string $verifier): ?array
    {
        $response = $this->http
            ->acceptJson()
            ->asJson()
            ->post($this->service.'/robot-council/api/device/token', [
                'device_code' => $deviceCode,
                'code_verifier' => $verifier,
            ]);

        if ($response->successful()) {
            return $this->decode($response->json());
        }

        $error = $response->json('error');

        // The one state that means "keep waiting". Everything else is terminal, and the caller
        // should stop rather than poll a dead code until the clock runs out.
        if ($error === 'authorization_pending') {
            return null;
        }

        throw new RuntimeException($this->explain($response->status(), $response->json()));
    }

    /**
     * A JSON body, or a refusal saying so.
     *
     * `Response::json()` answers null for a body that is empty or is not JSON at all -- a captive
     * portal, an intercepting proxy, or a `--service` that happens to answer 200 with HTML. Without
     * this the declared `array` return raised a `TypeError` on the request path, and on the token
     * path the null read as `authorization_pending`, so the command polled to its deadline and then
     * reported that the code had expired. A wrong diagnosis is worse than a blunt one.
     *
     * @param  mixed  $body  Whatever the client decoded.
     * @return array<string, mixed> The body.
     *
     * @throws RuntimeException When it is not a JSON object.
     */
    private function decode(mixed $body): array
    {
        if (! \is_array($body)) {
            throw new RuntimeException('The service answered with something that is not JSON. Check the --service URL.');
        }

        /** @var array<string, mixed> $body */
        return $body;
    }

    /**
     * What went wrong, in words a developer can act on.
     *
     * **Never includes the response body wholesale.** A token endpoint's body is the one place a
     * credential appears, and an error path that echoes it would put a credential on stderr.
     *
     * @param  int  $status  The HTTP status.
     * @param  mixed  $body  The decoded body, which is inspected rather than printed.
     */
    private function explain(int $status, mixed $body): string
    {
        $error = \is_array($body) ? ($body['error'] ?? null) : null;

        return match (true) {
            $error === 'access_denied' => 'The enrollment was denied.',
            $error === 'expired_token' => 'The code expired before it was approved. Run enroll again.',
            $error === 'invalid_grant' => 'The service rejected this code. Run enroll again.',
            $status === 422 => 'The service refused the request as invalid. Check the harness and machine label.',
            $status === 429 => 'The service is rate limiting this machine. Wait a moment and try again.',
            \is_string($error) => sprintf('The service answered `%s`.', Terminal::safe($error)),
            default => sprintf('The service answered HTTP %d.', $status),
        };
    }
}
