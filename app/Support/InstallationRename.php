<?php

declare(strict_types=1);

namespace App\Support;

use App\Support\Credentials\Credential;
use Illuminate\Http\Client\Factory;
use RuntimeException;

/**
 * This machine renaming the installation it is enrolled as (#359).
 *
 * The service half is `PATCH {prefix}/api/installation` (`robot-council/core#590`, merged as
 * `robot-council/core#591`). It authenticates as the installation, names no installation in the
 * request, and renames only the caller, so a session token and another machine's credential are
 * refused at the door with 401. That is the decision on #359: the machine's own credential renames
 * its installation and nothing else does, which is why this takes the installation credential and
 * starts no session.
 *
 * **Nothing is stored locally.** The label travels at enrollment only (`DeviceCodeFlow`), and the
 * credential stores key by service and harness, so a rename leaves nothing on this machine to keep
 * in step.
 *
 * **Every refusal is told in the service's own words where it gives some.** A refused label, or
 * one another live installation of the same developer and harness already holds, is a 422 with the
 * reason in `errors.machine_label`; an unchanged label is a 409 with a `message`. Both are
 * reduced with `Terminal::safe()` because they arrive from the service and are printed.
 */
final class InstallationRename
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
     * Rename the installation.
     *
     * @param  string  $label  The new machine label, sent as given so the service's rules decide.
     * @return array{old: string, new: string} The labels, as the service reported them.
     *
     * @throws RuntimeException When the service refuses, in words.
     */
    public function rename(string $label): array
    {
        $response = $this->http
            ->acceptJson()
            ->asJson()
            ->withToken($this->installation->reveal())
            ->patch($this->service.'/robot-council/api/installation', ['machine_label' => $label]);

        $body = $response->json();
        $body = \is_array($body) ? $body : [];

        if ($response->successful()) {
            $old = $body['old_label'] ?? null;
            $new = $body['new_label'] ?? null;

            if (! \is_string($old) || ! \is_string($new)) {
                throw new RuntimeException('The service renamed the installation but did not say what it was called before and after.');
            }

            return ['old' => $old, 'new' => $new];
        }

        throw new RuntimeException($this->explain($response->status(), $body));
    }

    /**
     * What went wrong, in words a developer can act on.
     *
     * @param  int  $status  The HTTP status.
     * @param  array<array-key, mixed>  $body  The decoded body, inspected rather than printed.
     */
    private function explain(int $status, array $body): string
    {
        $message = \is_string($body['message'] ?? null) ? Terminal::safe($body['message']) : null;

        return match (true) {
            $status === 401 => 'This machine is not enrolled, or its credential was revoked. Run `robot-council enroll`.',
            $status === 422 => $this->reason($body) ?? $message ?? 'The service refused that label.',
            $status === 409 => $message ?? 'The installation already has that label, so nothing changed.',
            $status === 404 => $message ?? 'The service has no such installation to rename, or does not offer renaming yet.',
            $status === 429 => 'The service is rate limiting this machine. Wait a moment and try again.',
            default => sprintf('The service answered HTTP %d.', $status),
        };
    }

    /**
     * The first reason the service gave for refusing the label, or null when it gave none.
     *
     * @param  array<array-key, mixed>  $body  The decoded body.
     */
    private function reason(array $body): ?string
    {
        $errors = $body['errors'] ?? null;
        $reasons = \is_array($errors) ? ($errors['machine_label'] ?? null) : null;

        if (! \is_array($reasons)) {
            return null;
        }

        foreach ($reasons as $reason) {
            if (\is_string($reason) && trim($reason) !== '') {
                return Terminal::safe($reason);
            }
        }

        return null;
    }
}
