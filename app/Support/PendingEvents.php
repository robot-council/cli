<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Sleep;
use JsonException;
use RuntimeException;

/**
 * Where the bridge leaves fleet events for a harness-side hook to pick up.
 *
 * **It exists because the two halves are different processes with different lifetimes.** The bridge
 * is long-running and holds the session, the credential and the feed cursor; a stop hook is a
 * short-lived process the harness spawns at a turn boundary and which knows none of those. A file
 * is what they can both reach (cli#60).
 *
 * **Keyed by the bridge's identity, not by its session id**, for the same reason: the hook cannot
 * know a session id, and it can know the service, the harness and the project because they are what
 * its own configuration already carries.
 *
 * **And by the checkout, when no project is named (#299).** Every session launched from one
 * user-level harness configuration has the same service, harness and project, so with those alone
 * all of a machine's sessions shared one sink, and the first stop hook to run took every session's
 * events -- measured with three bridges on one Mac. The bridge and the hook both run in the
 * project directory, so both can resolve the checkout's git directory, which differs per worktree
 * and is the same from any subdirectory. Two sessions in ONE checkout still share (#300). A named
 * project keeps the key it always had, so an install that passes `--project` loses nothing.
 *
 * Under `XDG_STATE_HOME` rather than beside the credential in `XDG_CONFIG_HOME`: this is transient
 * state a fresh run may discard, not configuration a person edits. Never the working directory,
 * because a file written beside a project gets committed.
 */
final class PendingEvents
{
    /**
     * The directory name under the user's state directory.
     */
    public const string DIRECTORY = 'robot-council';

    /**
     * What every entry this side wrote is named with.
     *
     * **A sink holds two kinds of thing, and only one of them came from the fleet.** Everything the
     * follower leaves is a fleet event; a bridge may also leave an account of its own, and a reader
     * has to be able to tell them apart -- `PendingCommand` renders an untyped entry as `event`, so
     * anything not marked would read as something the feed delivered. Core defines 25 event types
     * and none uses this prefix (read from `Models\FleetEventType`), so the whole namespace is free
     * and the distinction is structural rather than a reserved word.
     */
    public const string LOCAL_PREFIX = 'bridge.';

    /**
     * The local entry saying this session's capacity moved after the join (cli#324).
     *
     * **About the session that wrote it, so it ends with that session**, unlike the session-ended
     * record: `clearFleetEvents()` drops it. Left behind, it would reach the next session's agent,
     * whose own join result gave the capacity in effect now, and contradict it.
     */
    public const string CAPACITY_CHANGED = self::LOCAL_PREFIX.'capacity-changed';

    /**
     * The key an entry carries once `pending` has printed it, holding when (cli#341).
     *
     * **A mark rather than a removal**, so delivery is at least once: an entry is removed by the
     * NEXT `pending`, never by the one that printed it. A hook killed between reading the sink and
     * printing it leaves the entries unmarked, and the next turn end prints them again.
     */
    // Under the bridge's own prefix, so no field the fleet ever sends can be mistaken for it
    public const string DELIVERED = self::LOCAL_PREFIX.'delivered_at';

    /**
     * How many events one sink keeps.
     *
     * A bound rather than a policy: an agent that is idle for a weekend while the fleet is busy
     * would otherwise return to a file nobody can read and a turn that starts with a month of
     * history. The oldest go first, because the newest are what a harness can still act on.
     */
    public const int MAX_EVENTS = 200;

    /**
     * How long the shutdown clear waits for another holder of the sink's lock.
     *
     * **Bounded, because it runs in a bridge's shutdown.** A blocking lock let a stuck reader hold
     * the bridge open after `SIGTERM`, and bought nothing in exchange: a harness that escalates to
     * `SIGKILL` skips the `finally` the clear runs in altogether (#223, #228). The session has
     * already ended by then, so nothing on the fleet waits on this.
     */
    public const int CLEAR_WAIT_MILLISECONDS = 2000;

    /**
     * How long the shutdown clear sleeps between attempts at the lock.
     */
    public const int CLEAR_RETRY_MILLISECONDS = 50;

    /**
     * The harness that runs one bridge for the whole application, whose sink has no checkout (#327).
     */
    public const string APP_WIDE_HARNESS = 'cursor';

    /**
     * How many times to ask git before keying by the directory itself for this one call.
     */
    public const int CHECKOUT_ATTEMPTS = 3;

    /**
     * Checkouts already resolved in this process, by the directory they were resolved from.
     *
     * **Only definite answers are kept**, and shared across instances, so a bridge's two sinks --
     * the one the join opens and the one keep-alives read -- cannot come to different answers.
     *
     * @var array<string, string>
     */
    private static array $checkouts = [];

    /**
     * Checkouts whose git directory timed out on the last try, by directory (cli#345).
     *
     * Their key fell back to the plain path, which names a different sink from the one keyed by the
     * git directory, so a strict read refuses rather than reporting that other sink's emptiness.
     * **A timeout only.** A git that is missing, or a checkout that no longer exists, falls back the
     * same way every time, in the bridge as in the hook, so the two agree and the read goes ahead.
     *
     * @var array<string, true>
     */
    private static array $unresolved = [];

    /**
     * @param  string  $service  The fleet's base URL.
     * @param  string  $harness  Which harness this bridge is.
     * @param  string|null  $projectId  The checkout this bridge is working, when one was named.
     * @param  string|null  $checkoutDirectory  Where to resolve the checkout from when no project
     *                                          is named; the current working directory by default.
     */
    public function __construct(
        private readonly string $service,
        private readonly string $harness,
        private readonly ?string $projectId = null,
        private readonly ?string $checkoutDirectory = null,
        private readonly bool $useRecordedKey = false,
    ) {}

    /**
     * Whether the last key came from the bridge's record rather than from resolving the checkout.
     */
    private bool $keyFromRecord = false;

    /**
     * Add events to the sink, keeping the newest.
     *
     * @param  list<array<array-key, mixed>>  $events  The events to leave for the harness.
     *
     * @throws RuntimeException When the sink cannot be written.
     */
    public function add(array $events): void
    {
        if ($events === []) {
            return;
        }

        $handle = $this->openForWriting();

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException(sprintf('Could not lock `%s` to leave fleet events in.', $this->path()));
            }

            // Read and write under ONE lock on ONE handle. Two `flock` calls on two handles would
            // deadlock this process against itself, and a read outside the lock is the defect this
            // replaces: a concurrent `add()` landing between the two halves was overwritten whole.
            $contents = stream_get_contents($handle);

            $kept = $this->bound([
                ...$this->decode($contents === false ? '' : $contents),
                ...$events,
            ]);

            $encoded = json_encode($kept, JSON_THROW_ON_ERROR);

            rewind($handle);

            if (! ftruncate($handle, 0) || fwrite($handle, $encoded) === false) {
                throw new RuntimeException(sprintf('Could not write fleet events to `%s`.', $this->path()));
            }

            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Leave a notice this side wrote, replacing any earlier one of the same type.
     *
     * **Replaced rather than appended, because a notice is a fact about the sink and not an event
     * in it.** A bridge says it once, but the sink is keyed by service, harness and project and
     * outlives every process that writes it -- and `clearFleetEvents()` deliberately preserves
     * what is already there. Measured: two bridges reaching the same news with no turn boundary
     * between them left the agent the same paragraph twice, with nothing to say whether that meant
     * one ending or two.
     *
     * @param  string  $type  The type, which carries `self::LOCAL_PREFIX`.
     * @param  string  $body  What the agent reads.
     *
     * @throws RuntimeException When the sink cannot be written.
     */
    public function leaveNotice(string $type, string $body): void
    {
        $handle = $this->openForWriting();

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException(sprintf('Could not lock `%s` to leave a notice in.', $this->path()));
            }

            $contents = stream_get_contents($handle);

            $kept = $this->bound([
                ...array_values(array_filter(
                    $this->decode($contents === false ? '' : $contents),
                    static fn (array $event): bool => ($event['type'] ?? null) !== $type
                )),
                ['type' => $type, 'body' => $body, 'created_at' => gmdate('c')],
            ]);

            $encoded = json_encode($kept, JSON_THROW_ON_ERROR);

            rewind($handle);

            if (! ftruncate($handle, 0) || fwrite($handle, $encoded) === false) {
                throw new RuntimeException(sprintf('Could not write a notice to `%s`.', $this->path()));
            }

            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Everything not yet delivered, having removed what an earlier `pending` delivered (cli#341).
     *
     * **At least once, not at most once.** `drain()` emptied the sink before the hook had printed
     * anything, so a hook killed at its timeout on a machine under load lost every event in it:
     * measured on 2026-09-28, a sink read and emptied at a turn end whose output never reached the
     * agent. Here nothing is removed until a later call, and only what an earlier call marked
     * delivered (`markDelivered()`), so what this call returns is still in the sink if the process
     * dies before it is printed. The cost is a duplicate when a hook dies after printing and
     * before marking.
     *
     * Under one lock, for the reason `drain()` gives.
     *
     * @return list<array<array-key, mixed>> What is waiting, oldest first.
     */
    public function deliver(): array
    {
        $this->locatablePath();

        return $this->rewrite(change: static fn (array $events): array => array_values(array_filter(
            $events,
            static fn (array $event): bool => ! isset($event[self::DELIVERED])
        )),
            strict: true);
    }

    /**
     * Mark the given entries delivered, once they have been printed (cli#341).
     *
     * **Matched by content**, since an entry's content is all it has in common with the copy
     * `deliver()` returned: an entry the bridge replaced since (`leaveNotice()`) is a different
     * entry, stays unmarked, and is printed at the next turn end.
     *
     * @param  list<array<array-key, mixed>>  $printed  What `deliver()` returned and was printed.
     */
    public function markDelivered(array $printed): void
    {
        if ($printed === []) {
            return;
        }

        $keys = array_fill_keys(array_map(static fn (array $event): string => hash('sha256', (string) json_encode($event)), $printed), true);
        $now = time();

        $this->rewrite(static fn (array $events): array => array_map(
            static fn (array $event): array => ! isset($event[self::DELIVERED]) && isset($keys[hash('sha256', (string) json_encode($event))])
                ? [...$event, self::DELIVERED => $now]
                : $event,
            $events
        ));
    }

    /**
     * Replace the sink's entries with what `$change` makes of them, under one exclusive lock.
     *
     * **Strict for `deliver()`** (cli#345): a lock not obtained in time, a file that will not open, or
     * contents that do not parse throw `UnreadableSink` and leave the file exactly as it was, where
     * the lenient form would have decoded nothing and written that nothing back over the events.
     *
     * @param  callable(list<array<array-key, mixed>>): list<array<array-key, mixed>>  $change
     * @param  bool  $strict  Whether a failed read throws rather than answering nothing.
     * @return list<array<array-key, mixed>> The entries undelivered after the change.
     *
     * @throws UnreadableSink When strict and the sink could not be read.
     */
    private function rewrite(callable $change, bool $strict = false): array
    {
        $path = $this->path();

        if (! is_file($path)) {
            return [];
        }

        $handle = @fopen($path, 'c+');

        if ($handle === false) {
            if ($strict) {
                throw new UnreadableSink(sprintf('Could not open the sink `%s` to read it.', $path));
            }

            return [];
        }

        try {
            if ($strict) {
                $this->lockOrFail($handle, LOCK_EX);
            } elseif (! flock($handle, LOCK_EX)) {
                return [];
            }

            $contents = stream_get_contents($handle);
            $contents = $contents === false ? '' : $contents;
            $events = $change($strict ? $this->decodeOrFail($contents) : $this->decode($contents));

            try {
                $encoded = $events === [] ? '' : json_encode($events, JSON_THROW_ON_ERROR);
            } catch (JsonException $jsonException) {
                if ($strict) {
                    throw new UnreadableSink(sprintf('The sink `%s` could not be written back: %s. It was left as it was.', $path, $jsonException->getMessage()), 0, $jsonException);
                }

                return [];
            }

            rewind($handle);
            ftruncate($handle, 0);
            fwrite($handle, $encoded);
            fflush($handle);

            return array_values(array_filter($events, static fn (array $event): bool => ! isset($event[self::DELIVERED])));
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Take everything waiting, leaving the sink empty.
     *
     * **Read and truncate under one lock**, because a hook draining while the bridge appends would
     * otherwise lose whatever arrived between the two operations -- and a lost wake-up looks
     * exactly like a quiet fleet.
     *
     * @return list<array<array-key, mixed>> What was waiting, oldest first.
     */
    public function drain(): array
    {
        $path = $this->path();

        if (! is_file($path)) {
            return [];
        }

        $handle = fopen($path, 'c+');

        if ($handle === false) {
            return [];
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                return [];
            }

            $contents = stream_get_contents($handle);

            ftruncate($handle, 0);
            fflush($handle);

            return $this->decode($contents === false ? '' : $contents);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * What `pending` would print next, as `peek()` does, but refusing rather than guessing (cli#345).
     *
     * **`peek()` answers "nothing" for a sink it could not read**, which the bridge wants: its repeat
     * fingerprint must never throw. A person or a hook asking what is waiting needs the difference,
     * so this throws where `peek()` returns an empty list: a checkout it could not resolve, a lock not
     * obtained within `CLEAR_WAIT_MILLISECONDS`, a file it could not open, or one that does not parse.
     * A sink that does not exist yet is genuinely empty, and answers so.
     *
     * @return list<array<array-key, mixed>> What is waiting, oldest first.
     *
     * @throws UnreadableSink When the sink exists, or should, and could not be read.
     */
    public function inspect(): array
    {
        $path = $this->locatablePath();

        if (! is_file($path)) {
            return [];
        }

        $handle = @fopen($path, 'r');

        if ($handle === false) {
            throw new UnreadableSink(sprintf('Could not open the sink `%s` to read it.', $path));
        }

        try {
            $this->lockOrFail($handle, LOCK_SH);

            $contents = stream_get_contents($handle);

            return array_values(array_filter(
                $this->decodeOrFail($contents === false ? '' : $contents),
                static fn (array $event): bool => ! isset($event[self::DELIVERED])
            ));
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * This sink's path, or a refusal when the checkout its key depends on could not be resolved.
     *
     * @throws UnreadableSink When the key fell back to the checkout's plain path.
     */
    private function locatablePath(): string
    {
        $path = $this->path();
        $directory = $this->checkoutDirectory ?? getcwd();

        // Only a key resolved from the checkout here can have fallen back; the bridge's record is its
        // own answer, whatever this process's git would say (cli#346)
        if ($this->keyedByCheckout() && ! $this->keyFromRecord && isset(self::$unresolved[\is_string($directory) ? $directory : ''])) {
            throw new UnreadableSink(sprintf(
                'Could not tell which checkout this is: `git rev-parse` ran out of time %d times, at %d seconds each, so its sink cannot be found.',
                self::CHECKOUT_ATTEMPTS,
                Checkout::TIMEOUT_SECONDS,
            ));
        }

        return $path;
    }

    /**
     * Take a lock within `CLEAR_WAIT_MILLISECONDS`, or refuse.
     *
     * @param  resource  $handle  The open sink.
     * @param  LOCK_SH|LOCK_EX  $operation  Which lock to take.
     *
     * @throws UnreadableSink When the lock was not obtained in time.
     */
    private function lockOrFail(mixed $handle, int $operation): void
    {
        if (! $this->lockWithinTheWait($handle, $operation)) {
            throw new UnreadableSink(sprintf(
                'Could not lock the sink `%s` within %s seconds, so it was left as it was.',
                $this->path(),
                self::CLEAR_WAIT_MILLISECONDS / 1000,
            ));
        }
    }

    /**
     * Entries from a sink's contents, or a refusal when they do not parse as a list of entries.
     *
     * @param  string  $contents  What the sink file holds.
     * @return list<array<array-key, mixed>>
     *
     * @throws UnreadableSink When the contents are not empty and not a JSON list.
     */
    private function decodeOrFail(string $contents): array
    {
        if (trim($contents) === '') {
            return [];
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            throw new UnreadableSink(sprintf('The sink `%s` does not parse: %s. It was left as it was; move it aside to let delivery resume.', $this->path(), $jsonException->getMessage()), 0, $jsonException);
        }

        if (! \is_array($decoded) || ! array_is_list($decoded)) {
            throw new UnreadableSink(sprintf('The sink `%s` is not a list of events. It was left as it was; move it aside to let delivery resume.', $this->path()));
        }

        return array_values(array_filter($decoded, \is_array(...)));
    }

    /**
     * Everything waiting, leaving the sink exactly as it was.
     *
     * **This is not `drain()` with the truncate removed, and the difference is the point.** The
     * first version of `--peek` was `drain()` followed by `add()`, which emptied the sink and put
     * it back: a bridge appending in that window had its event ordered behind older ones, or
     * dropped outright once the sink was at `MAX_EVENTS`, and a process dying between the two
     * halves lost everything (`robot-council/cli#71`). A read that does not write cannot do any of
     * that.
     *
     * `LOCK_SH` rather than `LOCK_EX`, because several readers are harmless and only a writer has
     * to be excluded -- and a hook peeking must never make the bridge wait to record an event.
     *
     * @return list<array<array-key, mixed>> What is waiting, oldest first.
     */
    public function peek(): array
    {
        $path = $this->path();

        if (! is_file($path)) {
            return [];
        }

        $handle = fopen($path, 'r');

        if ($handle === false) {
            return [];
        }

        try {
            if (! flock($handle, LOCK_SH)) {
                return [];
            }

            $contents = stream_get_contents($handle);

            // What `pending` would print next: an entry already delivered is waiting for nothing
            return array_values(array_filter(
                $this->decode($contents === false ? '' : $contents),
                static fn (array $event): bool => ! isset($event[self::DELIVERED])
            ));
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Whether anything is waiting, without taking it.
     */
    public function isEmpty(): bool
    {
        return $this->peek() === [];
    }

    /**
     * Drop what the fleet said, and keep what this side wrote.
     *
     * Called when a session ends, so a harness that restarts does not inherit the previous
     * session's unread events -- they name tasks and locks that session held, which the new one
     * does not.
     *
     * **A `bridge.` entry survives it, and that is the difference from removing the file.** The one
     * such entry today says the fleet ended the previous session, which is not that session's work
     * to inherit but the next agent's explanation for why it is starting over -- the single thing
     * in the sink written FOR the reader that comes after. Scoping the clearing here rather than
     * ordering the caller's shutdown means nothing breaks if that order later changes, which a
     * comment asking for an order does not give.
     *
     * **Except a capacity change, which is the session's own news** (`CAPACITY_CHANGED`): the next
     * session learns its capacity from its own join.
     *
     * **It gives up rather than waits** when another process holds the sink for longer than
     * `CLEAR_WAIT_MILLISECONDS`, leaving the sink as it was and saying so once. `add()`, `drain()`
     * and `peek()` keep their blocking locks, since none of them sits in a shutdown path.
     *
     * @param  (callable(string): void)|null  $diagnostic  Told when the clear gives up.
     */
    public function clearFleetEvents(?callable $diagnostic = null): void
    {
        $path = $this->path();

        if (! is_file($path)) {
            return;
        }

        $handle = fopen($path, 'c+');

        if ($handle === false) {
            return;
        }

        try {
            if (! $this->lockWithinTheWait($handle)) {
                if ($diagnostic !== null) {
                    // "Could not lock" rather than "another process held it": `flock` also fails
                    // on a filesystem that cannot lock at all, and that retries for the same wait.
                    $diagnostic(sprintf(
                        'Left the unread fleet events in `%s`: could not lock it within %s seconds.',
                        $path,
                        self::CLEAR_WAIT_MILLISECONDS / 1000,
                    ));
                }

                return;
            }

            $contents = stream_get_contents($handle);

            $kept = array_values(array_filter(
                $this->decode($contents === false ? '' : $contents),
                fn (array $event): bool => $this->isLocal($event) && ($event['type'] ?? null) !== self::CAPACITY_CHANGED
            ));

            // **Encoded before the file is emptied, and a failure RETURNS rather than falling
            // through.** Swallowing it and carrying on would truncate the sink and destroy the very
            // record this method exists to keep, which is the one outcome worse than not clearing
            // at all. Silent by design: this runs inside a shutdown that must not throw.
            try {
                $encoded = $kept === [] ? '' : json_encode($kept, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return;
            }

            rewind($handle);

            // A failed `ftruncate` leaves the previous session's events in place. That is the wrong
            // outcome and the only one available here, because unlike `add()` this cannot throw.
            if (ftruncate($handle, 0) && $encoded !== '') {
                fwrite($handle, $encoded);
            }

            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Take the sink's lock without blocking, retrying until the wait runs out.
     *
     * Counted in attempts rather than against a clock, so a test that fakes `Sleep` sees the same
     * schedule a real run does: a first try, then one after each sleep, until the sleeps add up to
     * `CLEAR_WAIT_MILLISECONDS`.
     *
     * @param  resource  $handle  The open sink.
     * @param  LOCK_SH|LOCK_EX  $operation  Which lock to take.
     * @return bool Whether the lock was taken.
     */
    private function lockWithinTheWait(mixed $handle, int $operation = LOCK_EX): bool
    {
        for ($waited = 0; ; $waited += self::CLEAR_RETRY_MILLISECONDS) {
            if (flock($handle, $operation | LOCK_NB)) {
                return true;
            }

            if ($waited >= self::CLEAR_WAIT_MILLISECONDS) {
                return false;
            }

            Sleep::for(self::CLEAR_RETRY_MILLISECONDS)->milliseconds();
        }
    }

    /**
     * The seat lock this bridge holds for the life of the process, once taken.
     *
     * @var resource|null
     */
    private mixed $seat = null;

    /**
     * Take this sink's seat for as long as this process runs, or report that another bridge has it (#320).
     *
     * **One seat per checkout, enforced by a warning rather than a refusal.** Two sessions in one
     * checkout share one sink (#299), so whichever stop hook runs first takes both sessions'
     * events, and nothing reports it. This lock is how a second bridge finds out.
     *
     * **An advisory `flock`, which the operating system releases when the holder exits**, however
     * it exits -- so a bridge that was killed leaves no stale lock behind, which a lock FILE whose
     * existence meant "held" would. The holder's pid is written to a separate file, because a lock
     * on Windows is mandatory and another process cannot read the locked file itself.
     *
     * Best-effort: when the lock cannot be opened or taken for any reason but another holder, this
     * answers true, since a bridge that cannot tell must not warn about a sibling it has not seen.
     *
     * **The key is resolved at this moment.** If git cannot answer at launch, the seat keys by the
     * folder while the sink, resolved again at the join, may key by the git directory; a second
     * bridge then takes a different seat and is not warned. Accepted: it needs git to fail three
     * times at launch.
     *
     * @return bool True when this process now holds the seat, false when another does.
     */
    public function claimSeat(): bool
    {
        if ($this->seat !== null) {
            return true;
        }

        $directory = $this->directory();

        if (! is_dir($directory) && ! @mkdir($directory, 0o700, true) && ! is_dir($directory)) {
            return true;
        }

        // `e` sets close-on-exec where the platform has it, so no child this process starts holds a
        // copy of the lock and keeps it after this process is killed. Windows has no such flag;
        // there, `mcp` takes the seat after starting its one long-lived child
        $handle = @fopen($this->seatPath(), PHP_OS_FAMILY === 'Windows' ? 'c' : 'ce');

        if ($handle === false) {
            return true;
        }

        @chmod($this->seatPath(), 0o600);

        if (! flock($handle, LOCK_EX | LOCK_NB, $wouldBlock)) {
            fclose($handle);

            // Held by someone else only when the lock WOULD have blocked. A file system that cannot
            // lock at all (some network homes) fails the same call, and must not warn every bridge.
            // No test reaches that second case, since it needs a file system without locking; the
            // held case is `SeatTest`'s
            return $wouldBlock !== 1;
        }

        $this->seat = $handle;

        // Written aside and renamed into place, so a second bridge reading it never sees it empty
        $pidPath = $this->seatPath().'.pid';
        $staging = $pidPath.'.'.getmypid();

        if (@file_put_contents($staging, (string) getmypid()) !== false) {
            @chmod($staging, 0o600);
            @rename($staging, $pidPath);
        }

        return true;
    }

    /**
     * The pid of the bridge holding this sink's seat, as it recorded it, or null when unknown.
     */
    public function seatHolder(): ?int
    {
        $recorded = @file_get_contents($this->seatPath().'.pid');

        return \is_string($recorded) && preg_match('/^\d+$/', trim($recorded)) === 1 ? (int) trim($recorded) : null;
    }

    /**
     * Where this sink's seat lock lives.
     */
    public function seatPath(): string
    {
        return $this->directory().'/'.$this->key().'.seat';
    }

    /**
     * Record that a turn has just ended, for a bridge keeping the session's cache warm (#279).
     *
     * **The stop hook is the one thing that sees every turn end**, and this is how it tells the
     * bridge: a file beside the sink whose modification time is the moment. It holds nothing, so
     * there is nothing in it to read wrongly. Best-effort, because a hook must never fail a turn over
     * bookkeeping; a mark that could not be written makes the bridge think the session idler than it
     * is, which costs one extra short turn.
     */
    public function markTurnEnded(): void
    {
        $directory = $this->directory();

        if (! is_dir($directory) && ! @mkdir($directory, 0o700, true) && ! is_dir($directory)) {
            return;
        }

        $path = $this->turnMarkPath();

        if (@touch($path)) {
            @chmod($path, 0o600);
        }
    }

    /**
     * When a turn last ended, as a Unix timestamp, or null when none has been recorded.
     */
    public function turnEndedAt(): ?int
    {
        $path = $this->turnMarkPath();

        // Read afresh each time: PHP caches `stat` results, and a cached one would be a mark that
        // never moves
        clearstatcache(true, $path);

        $modified = @filemtime($path);

        return $modified === false ? null : $modified;
    }

    /**
     * Where the record of this sink's last join lives, for a restarted Cursor bridge (#333).
     */
    public function joinRecordPath(): string
    {
        return $this->directory().'/'.$this->key().'.joined';
    }

    /**
     * Where the turn-end mark lives.
     */
    public function turnMarkPath(): string
    {
        return $this->directory().'/'.$this->key().'.turn';
    }

    /**
     * Where this bridge's sink lives.
     */
    public function path(): string
    {
        return $this->directory().'/'.$this->key().'.json';
    }

    /**
     * The sink, open for reading and writing, created narrow if it is absent.
     *
     * @return resource The open handle, which the caller locks, uses and closes.
     *
     * @throws RuntimeException When the sink cannot be created or opened.
     */
    private function openForWriting(): mixed
    {
        $directory = $this->directory();

        if (! is_dir($directory) && ! mkdir($directory, 0o700, true) && ! is_dir($directory)) {
            throw new RuntimeException(sprintf('Could not create `%s` to leave fleet events in.', $directory));
        }

        $path = $this->path();

        // Created narrow before anything is written, the way the credential store does: event
        // bodies are other developers' agents' words, and this file sits in a shared home.
        //
        // **The mode is re-asserted on every open, not only at creation.** The sink used to be
        // removed at every session end and recreated narrow; it now persists, so a `chmod` that
        // failed once -- or a file recreated at the umask default in the window between `is_file`
        // and `fopen` -- would otherwise stay readable for the life of the machine.
        if (! is_file($path)) {
            touch($path);
        }

        @chmod($path, 0o600);

        // `c+` rather than `w+`: it does not truncate on open, so the handle can be locked BEFORE
        // anything is destroyed. `w+` would empty the file for any concurrent reader in the window
        // between opening and taking the lock.
        $handle = fopen($path, 'c+');

        if ($handle === false) {
            throw new RuntimeException(sprintf('Could not open `%s` to leave fleet events in.', $path));
        }

        return $handle;
    }

    /**
     * Whether an entry was written by this side rather than delivered by the fleet.
     *
     * One predicate, because three things turn on it -- the bound, the clearing, and the
     * rendering -- and a fourth reading of what `bridge.` means is how they drift apart.
     *
     * @param  array<array-key, mixed>  $event  One entry from the sink.
     */
    private function isLocal(array $event): bool
    {
        return \is_string($event['type'] ?? null) && str_starts_with($event['type'], self::LOCAL_PREFIX);
    }

    /**
     * The newest events a sink may keep.
     *
     * **The bound counts only what the fleet sent, and that exemption is load-bearing.** A bare
     * slice over everything evicts from the head, and the one entry this side writes -- why the
     * previous session ended -- is at the head by the time it matters. Measured: a record followed
     * by `MAX_EVENTS` fleet events was sliced away, which is the busy-fleet case the bound exists
     * for, so the sink dropped the entry exactly when it was least reconstructible. There is at
     * most one local entry per type, because `leaveNotice()` replaces rather than appends, so the
     * exemption cannot grow the file without bound.
     *
     * The oldest fleet entries go, **in place**, so what survives keeps its order.
     *
     * @param  list<array<array-key, mixed>>  $events  Everything on offer, oldest first.
     * @return list<array<array-key, mixed>> What fits.
     */
    private function bound(array $events): array
    {
        $excess = \count(array_filter($events, fn (array $event): bool => ! $this->isLocal($event))) - self::MAX_EVENTS;

        if ($excess <= 0) {
            return $events;
        }

        $kept = [];

        foreach ($events as $event) {
            if ($excess > 0 && ! $this->isLocal($event)) {
                $excess--;

                continue;
            }

            $kept[] = $event;
        }

        return $kept;
    }

    /**
     * Decode a sink's contents, treating anything unreadable as empty.
     *
     * **A corrupt sink is emptiness, not an error.** It is a cache of things to tell an agent; a
     * half-written file is worth losing, and is not worth stopping a bridge or a turn for.
     *
     * @param  string  $contents  The raw file.
     * @return list<array<array-key, mixed>> The events it held.
     */
    private function decode(string $contents): array
    {
        if (trim($contents) === '') {
            return [];
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (! \is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, \is_array(...)));
    }

    /**
     * The directory sinks live in.
     */
    private function directory(): string
    {
        $configured = getenv('XDG_STATE_HOME');

        if (\is_string($configured) && trim($configured) !== '') {
            return rtrim($configured, '/\\').'/'.self::DIRECTORY.'/pending';
        }

        $home = getenv('HOME');

        if (! \is_string($home) || trim($home) === '') {
            $home = sys_get_temp_dir();
        }

        return rtrim($home, '/\\').'/.local/state/'.self::DIRECTORY.'/pending';
    }

    /**
     * A file name for this bridge's identity.
     *
     * Hashed rather than spelled out, because a service URL and a project id both contain
     * characters a path cannot carry, and a project id is a label a person chose.
     */
    private function key(): string
    {
        $parts = [rtrim($this->service, '/'), $this->harness, $this->projectId ?? ''];

        // Unchanged when a project is named, so its sink survives the upgrade. Otherwise a fourth
        // part, which also keeps the new key from ever equalling the old shared one: joined, even an
        // empty fourth part adds a separator the three-part key never had (#299).
        //
        // **Not for Cursor** (#327). Cursor runs one bridge for the whole app, in the home folder,
        // and runs its stop hook in `~/.cursor`, so neither folder is the project and the two keys
        // never matched: a Cursor seat's hook drained an empty sink while its bridge's filled.
        // Measured on Cursor 3.17.19. One bridge per app means one sink per app is the right match,
        // which is the key it had before #299
        $this->keyFromRecord = false;

        if ($this->keyedByCheckout()) {
            // **The bridge's own answer, where it left one** (cli#346). Resolving the checkout again
            // is what let a hook and its bridge key two different sinks: the git lookup that times
            // out in one process and answers in the other
            if ($this->useRecordedKey && ($recorded = $this->recordedKey()) !== null) {
                $this->keyFromRecord = true;

                return $recorded;
            }

            $parts[] = $this->checkout();
        }

        return substr(hash('sha256', implode("\0", $parts)), 0, 32);
    }

    /**
     * Whether this sink's key depends on the checkout: no project named, and not Cursor (#299, #327).
     */
    private function keyedByCheckout(): bool
    {
        return $this->projectId === null && $this->harness !== self::APP_WIDE_HARNESS;
    }

    /**
     * Resolve this sink's key from the checkout, and record it for the stop hook to read (cli#346).
     *
     * **Called once, by a bridge as it starts.** The bridge and its hook each resolved the checkout
     * for themselves, and a `git` that answered one and timed out for the other put them on two
     * sinks with nothing to say so. Now the bridge resolves it once, whatever it gets, and every
     * other reader of this sink -- the bridge's own follower and keep-alive, and the hook -- uses
     * that answer. A hook that finds no record, such as one beside a bridge from before this,
     * resolves on its own as it always did.
     *
     * Named from what both sides know without asking git: the service, the harness, the project and
     * the checkout's real path. Written aside and moved into place; best-effort, since a record that
     * could not be written leaves a hook resolving on its own, which is what happened before.
     */
    public function recordKey(): void
    {
        if (! $this->keyedByCheckout()) {
            return;
        }

        $parts = [rtrim($this->service, '/'), $this->harness, '', $this->checkout()];
        $key = substr(hash('sha256', implode("\0", $parts)), 0, 32);

        $directory = $this->directory();

        if (! is_dir($directory) && ! @mkdir($directory, 0o700, true) && ! is_dir($directory)) {
            return;
        }

        $path = $this->recordPath();
        $staging = $path.'.'.getmypid();

        if (@file_put_contents($staging, $key) === false) {
            return;
        }

        @chmod($staging, 0o600);

        if (! @rename($staging, $path)) {
            @unlink($staging);
        }
    }

    /**
     * The key the bridge recorded for this sink, or null when it left none that reads as one.
     */
    private function recordedKey(): ?string
    {
        $recorded = @file_get_contents($this->recordPath());

        return \is_string($recorded) && preg_match('/^[0-9a-f]{32}$/', trim($recorded)) === 1 ? trim($recorded) : null;
    }

    /**
     * Where the bridge records this sink's key, named without asking git.
     */
    private function recordPath(): string
    {
        $directory = $this->checkoutDirectory ?? getcwd();
        $parts = [rtrim($this->service, '/'), $this->harness, 'recorded-key', $this->comparable($this->realDirectory(\is_string($directory) ? $directory : ''))];

        return $this->directory().'/'.substr(hash('sha256', implode("\0", $parts)), 0, 32).'.key';
    }

    /**
     * The checkout, as both the bridge and its stop hook resolve it.
     *
     * The git directory where there is one, and the working directory itself where git says there
     * is none. **Lower-cased on Windows**, where a drive letter or folder can arrive in either case
     * from two processes started differently, and the file system treats the two as one.
     *
     * **A git that could not answer is asked again, and its silence is never kept.** Keyed by the
     * directory instead, a bridge whose first `git` timed out under load would write every event
     * for the rest of its life to a sink no hook reads. After `CHECKOUT_ATTEMPTS`, this one call
     * falls back to the directory, and the next asks again.
     */
    private function checkout(): string
    {
        $directory = $this->checkoutDirectory ?? getcwd();
        $directory = \is_string($directory) ? $directory : '';

        if (isset(self::$checkouts[$directory])) {
            return self::$checkouts[$directory];
        }

        $anyTimedOut = false;

        for ($attempt = 0; $attempt < self::CHECKOUT_ATTEMPTS; $attempt++) {
            $resolved = Checkout::resolveGitDirectory($directory === '' ? null : $directory, $timedOut);
            $anyTimedOut = $anyTimedOut || $timedOut;

            if ($resolved !== null) {
                unset(self::$unresolved[$directory]);

                return self::$checkouts[$directory] = $this->comparable($resolved === false ? $this->realDirectory($directory) : $resolved);
            }
        }

        if ($anyTimedOut) {
            self::$unresolved[$directory] = true;
        } else {
            unset(self::$unresolved[$directory]);
        }

        return $this->comparable($this->realDirectory($directory));
    }

    /**
     * A directory with its links resolved, or as given when it cannot be.
     */
    private function realDirectory(string $directory): string
    {
        $real = $directory === '' ? false : realpath($directory);

        return rtrim(str_replace('\\', '/', $real === false ? $directory : $real), '/');
    }

    /**
     * One spelling for a path two processes may spell differently.
     */
    private function comparable(string $path): string
    {
        return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    }
}
