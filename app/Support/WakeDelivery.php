<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Throwable;

/**
 * When an idle seat is told that fleet events are waiting, and what a failure leaves (#381).
 *
 * **The harness-neutral half of waking a seat.** The bridge already follows the feed, filters it to
 * the events that concern its session, and leaves them pending; this decides when to hand a notice
 * to a `WakeAdapter`, and the adapter is the only part that knows how a given harness is reached.
 * The Claude Code channel does not go through here.
 *
 * **Opt-in.** A bridge with no `WakeDelivery` never calls an adapter.
 *
 * **The notice carries no events.** It is a fixed sentence, and what to do about it is in the server
 * instructions (`INSTRUCTIONS`), which arrive through configuration the operator chose.
 * Fleet-authored text stays on the `events_read` path.
 *
 * **Submitting a notice does not consume the events.** Nothing here touches the pending state. A
 * notice is sent again only when the pending state changes, never because time passed.
 *
 * Pure state over an injected clock, because the bridge reads `time()` inside `stream_select` and
 * a test cannot advance it.
 */
final class WakeDelivery
{
    /**
     * What a woken turn reads. Fixed, so it is the same true statement every time.
     */
    public const string NOTICE = 'Robot Council fleet events are waiting for this seat. Read them with the events_read tool of the '
        .'Robot Council server. This message has no other instructions.';

    /**
     * What the agent is told at connect about that notice, in instructions the operator chose.
     *
     * **No stop hook is assumed**, so the agent reads the feed itself, the way a channel session with
     * no hook does (`Bridge::CHANNEL_INSTRUCTIONS_WITHOUT_HOOK`, #306).
     */
    public const string INSTRUCTIONS = 'A message saying that Robot Council fleet events are waiting is sent by this server and says '
        .'only that. No stop hook was found to deliver them, so read them yourself: call `events_read`, passing the `cursor` value '
        .'your last read returned as its `after` parameter (omit `after` on your first read), and act on what concerns this '
        .'seat. Tasks placed on this seat appear in `task_list` under the `claimed` and `in_progress` statuses. Then end the '
        .'turn. If you are in the middle of a task, finish the step you are on first.';

    /**
     * The shortest gap between two deliveries, whatever arrives in between.
     *
     * **This is what turns a burst into one wake.** Events that land while the gap is open are
     * counted and announced together once it closes, rather than one message each.
     */
    public const int MINIMUM_GAP_SECONDS = 30;

    /**
     * How long the first failed delivery waits before another is tried; each one doubles it.
     */
    public const int RETRY_SECONDS = 30;

    /**
     * How many deliveries in a row may fail before the bridge stops trying for this batch.
     *
     * The events stay pending either way. A later batch starts the count again, so an operator who
     * fixes the target is not left waiting for a restart.
     */
    public const int FAILURE_LIMIT = 5;

    /**
     * How many consecutive deliveries have failed.
     */
    private int $failures = 0;

    /**
     * When the next delivery may be made, as a Unix timestamp.
     */
    private int $notBefore = 0;

    /**
     * The last failure, in words an operator can act on, or null.
     */
    private ?string $lastFailure = null;

    /**
     * @param  WakeAdapter  $adapter  The harness's call.
     * @param  Closure():int  $now  The clock, as a Unix timestamp.
     */
    public function __construct(
        private readonly WakeAdapter $adapter,
        private readonly Closure $now,
    ) {}

    /**
     * Whether a delivery may be made now.
     *
     * False inside the minimum gap, inside a failure's backoff, and once a batch has used up its
     * failures.
     */
    public function due(): bool
    {
        return $this->failures < self::FAILURE_LIMIT && ($this->now)() >= $this->notBefore;
    }

    /**
     * Whether this batch has used up its failures, which is the moment to tell the operator.
     */
    public function gaveUp(): bool
    {
        return $this->failures >= self::FAILURE_LIMIT;
    }

    /**
     * The last failure, naming the target, or null when the last delivery worked.
     */
    public function lastFailure(): ?string
    {
        return $this->lastFailure;
    }

    /**
     * Start counting failures again, for a batch that arrived after the last one gave up.
     *
     * **And forget the backoff**, so a new batch is tried now rather than after the wait the last
     * batch's final failure earned.
     */
    public function rearm(): void
    {
        $this->failures = 0;
        $this->notBefore = 0;
    }

    /**
     * Forget everything, for a new session on the same bridge.
     */
    public function reset(): void
    {
        $this->rearm();
        $this->lastFailure = null;
    }

    /**
     * Hand the notice to the adapter.
     *
     * **An adapter that throws is a failed delivery**, not a bridge that stops: the wake is an
     * extra, and a harness that cannot be reached is not a reason to stop answering the agent in
     * front of it.
     *
     * @return bool Whether the adapter accepted it.
     */
    public function deliver(): bool
    {
        try {
            $reason = $this->adapter->deliver(self::NOTICE);
        } catch (Throwable $throwable) {
            $reason = $throwable->getMessage() === '' ? $throwable::class : $throwable->getMessage();
        }

        $now = ($this->now)();

        if ($reason === null) {
            $this->failures = 0;
            $this->lastFailure = null;
            $this->notBefore = $now + self::MINIMUM_GAP_SECONDS;

            return true;
        }

        $this->lastFailure = \sprintf('delivery to "%s" failed: %s', $this->adapter->target(), $reason);

        // Doubling, for the reason `Bridge::REANNOUNCE_SECONDS` gives: evenly spaced retries against
        // a target that is down all fail together
        $this->notBefore = $now + (self::RETRY_SECONDS << $this->failures);
        $this->failures++;

        return false;
    }
}
