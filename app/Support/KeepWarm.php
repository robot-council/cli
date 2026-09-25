<?php

declare(strict_types=1);

namespace App\Support;

use Closure;

/**
 * When an idle Claude Code session should be woken to keep its prompt cache warm (#279).
 *
 * **Why the bridge does this at all.** An idle session's cache expires, and the next turn pays to
 * write the whole conversation again -- for a fleet session idle between tasks, that lands on the
 * first turn of the next task. Claude Code documents that each request that hits the cache resets
 * its timer, so one short turn inside the TTL keeps it, and the bridge can already wake its own
 * session through the channel.
 *
 * **The bridge cannot see a turn, so this reads two things that can.** A continuation that only
 * runs Bash sends the bridge nothing (see `Bridge::REANNOUNCE_SECONDS`). What does see every turn
 * end is the stop hook, which leaves the time in a marker beside the sink (`PendingEvents::
 * markTurnEnded()`); what the bridge does see is a tool call arriving and a notice it sent, and
 * either means a turn is running or about to. The latest of those is when the session was last
 * active.
 *
 * **A keep-alive's own turn is not activity.** It ends, and the stop hook marks it like any other,
 * so without telling the two apart the ceiling below could never be reached. The first mark within
 * `ABSORB_SECONDS` of a keep-alive is taken as that keep-alive's turn. Bounded, because a harness
 * started without channels drops the notice and produces no turn -- and an unbounded flag would
 * then swallow the next real one.
 *
 * Pure state over an injected clock, because the bridge's loop reads `time()` inside
 * `stream_select` and a test cannot advance it.
 */
final class KeepWarm
{
    /**
     * How long after a keep-alive a turn end is still taken to be that keep-alive's.
     *
     * A woken turn told to do nothing ends in seconds; two minutes allows for a slow model and a
     * slow hook, and is still far below any interval that keeps a one-hour cache warm.
     */
    public const int ABSORB_SECONDS = 120;

    /**
     * What a keep-alive notice says.
     *
     * Content a woken turn treats as data, so what it should do is in the server instructions
     * (`INSTRUCTIONS`); this only has to be true.
     */
    public const string NOTICE = 'Nothing is waiting for this session. This notice only keeps its prompt cache warm.';

    /**
     * What the server instructions add when keep-alives are on.
     *
     * Only then, so a bridge without the option sends exactly what it sent before it existed.
     *
     * **Conditional, because a keep-alive can land in a running turn.** A turn that only runs Bash
     * and edits files moves nothing the bridge reads until it ends, so a long one looks idle. The
     * instruction must not tell an agent in the middle of work to drop it.
     */
    public const string INSTRUCTIONS = ' A notice whose meta carries `keep_warm` has nothing behind it: it only keeps this '
        ."session's prompt cache warm. If nothing else is in progress, end that turn at once, without tool calls; "
        .'if you are in the middle of a task, ignore it and carry on.';

    /**
     * When the session was last active, as a Unix timestamp.
     */
    private int $activeAt;

    /**
     * When the last keep-alive was sent, or null when none has been.
     */
    private ?int $sentAt = null;

    /**
     * The turn-end mark last read, so an unchanged one is not read as a new turn.
     *
     * **This is what lets the ceiling arrive.** A keep-alive's own mark is absorbed once; read again
     * on the next pass without this, it would count as activity and restart the idle clock.
     */
    private ?int $seenMark = null;

    /**
     * Whether the next mark may still be the last keep-alive's own turn.
     */
    private bool $awaitingOwnTurn = false;

    /**
     * @param  int  $intervalSeconds  How long the session must be idle before a keep-alive.
     * @param  int|null  $ceilingSeconds  How long idle before keep-alives stop, or null for never.
     * @param  Closure(): ?int  $turnEndedAt  When the stop hook last marked a turn end.
     * @param  Closure(): int  $clock  The time now, as a Unix timestamp.
     */
    public function __construct(
        private readonly int $intervalSeconds,
        private readonly ?int $ceilingSeconds,
        private readonly Closure $turnEndedAt,
        private readonly Closure $clock,
    ) {
        $this->activeAt = ($this->clock)();
    }

    /**
     * The schedule two option values ask for, or why there is none.
     *
     * Here rather than in the command, so the conversion and every refusal are testable over an
     * injected clock: a `* 60` dropped, or the two values swapped, would otherwise pass every test.
     *
     * **A ceiling at or below the interval is refused**, because it can never let one keep-alive
     * through, and silently sending none is the failure an operator cannot see.
     *
     * @param  string  $interval  What `--keep-warm` said.
     * @param  string|null  $ceiling  What `--keep-warm-for` said, or null when it was not given.
     * @param  Closure(): ?int  $turnEndedAt  When the stop hook last marked a turn end.
     * @param  Closure(): int  $clock  The time now, as a Unix timestamp.
     * @return self|string The schedule, or the sentence saying why keep-alives are off.
     */
    public static function fromOptions(string $interval, ?string $ceiling, Closure $turnEndedAt, Closure $clock): self|string
    {
        $intervalMinutes = self::minutes($interval);
        $ceilingMinutes = $ceiling === null ? null : self::minutes($ceiling);

        if ($intervalMinutes === null || ($ceiling !== null && $ceilingMinutes === null)) {
            return '--keep-warm and --keep-warm-for take a whole number of minutes, at least 1; keep-alives are off.';
        }

        if ($ceilingMinutes !== null && $ceilingMinutes <= $intervalMinutes) {
            return '--keep-warm-for must be longer than --keep-warm, or no keep-alive is ever sent; keep-alives are off.';
        }

        return new self($intervalMinutes * 60, $ceilingMinutes === null ? null : $ceilingMinutes * 60, $turnEndedAt, $clock);
    }

    /**
     * A positive whole number of minutes, or null.
     *
     * Digits only, so `55.5`, `-5` and `1e3` are refused rather than read as something else.
     *
     * @param  string  $given  What the option said.
     */
    private static function minutes(string $given): ?int
    {
        if (preg_match('/^\d{1,6}$/', $given) !== 1) {
            return null;
        }

        $minutes = (int) $given;

        return $minutes >= 1 ? $minutes : null;
    }

    /**
     * Something happened that a turn runs or is about to run for.
     */
    public function active(): void
    {
        $this->activeAt = max($this->activeAt, ($this->clock)());

        // A turn ending after this is at least partly somebody else's
        $this->awaitingOwnTurn = false;
    }

    /**
     * Whether a keep-alive is due now.
     *
     * **Idle is measured from the latest of every activity and every keep-alive**, so one is sent
     * per interval of silence and none while the session is working. **The ceiling is measured from
     * activity alone**, which is what makes it a limit on how long an abandoned session is kept.
     */
    public function due(): bool
    {
        $this->readMark();

        $now = ($this->clock)();

        if ($this->ceilingSeconds !== null && $now - $this->activeAt >= $this->ceilingSeconds) {
            return false;
        }

        return $now - max($this->activeAt, $this->sentAt ?? 0) >= $this->intervalSeconds;
    }

    /**
     * A keep-alive has been sent.
     */
    public function sent(): void
    {
        $this->sentAt = ($this->clock)();
        $this->awaitingOwnTurn = true;
    }

    /**
     * Take a new turn-end mark as activity, unless it is a keep-alive's own turn.
     */
    private function readMark(): void
    {
        $mark = ($this->turnEndedAt)();

        if ($mark === null || $mark === $this->seenMark) {
            return;
        }

        $this->seenMark = $mark;

        if ($this->awaitingOwnTurn && $this->sentAt !== null && $mark >= $this->sentAt && $mark - $this->sentAt <= self::ABSORB_SECONDS) {
            // Once: a second turn end after one keep-alive is a real turn. And it moves nothing,
            // which is what lets the ceiling arrive
            $this->awaitingOwnTurn = false;

            return;
        }

        $this->activeAt = max($this->activeAt, $mark);
    }
}
