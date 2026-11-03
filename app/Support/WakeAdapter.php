<?php

declare(strict_types=1);

namespace App\Support;

/**
 * What a harness supplies so the bridge can wake its idle seat (#381).
 *
 * **One call, one target.** An adapter is handed the id of the seat it reaches -- a thread, a
 * conversation, a window -- from configuration, and delivers one notice to it. It never chooses a
 * target by recency or by checkout; a target that cannot be reached is a failure the bridge reports,
 * never a reason to try another.
 */
interface WakeAdapter
{
    /**
     * The target this adapter reaches, as configured, for diagnostics.
     */
    public function target(): string;

    /**
     * Deliver the notice to the target.
     *
     * **Success means the harness accepted the message, and nothing more.** It does not mean a turn
     * ran or that the events were read: the bridge leaves the pending events alone either way.
     *
     * @param  string  $notice  The fixed notice, `WakeDelivery::NOTICE`.
     * @return string|null Null when the harness accepted it, otherwise why it did not, in words an operator can act on.
     */
    public function deliver(string $notice): ?string;
}
