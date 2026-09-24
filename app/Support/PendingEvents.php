<?php

declare(strict_types=1);

namespace App\Support;

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
 * its own configuration already carries. One bridge runs per identity, so one sink does too.
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
     * How many events one sink keeps.
     *
     * A bound rather than a policy: an agent that is idle for a weekend while the fleet is busy
     * would otherwise return to a file nobody can read and a turn that starts with a month of
     * history. The oldest go first, because the newest are what a harness can still act on.
     */
    public const int MAX_EVENTS = 200;

    /**
     * @param  string  $service  The fleet's base URL.
     * @param  string  $harness  Which harness this bridge is.
     * @param  string|null  $projectId  The checkout this bridge is working, when one was named.
     */
    public function __construct(
        private readonly string $service,
        private readonly string $harness,
        private readonly ?string $projectId = null
    ) {}

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

            return $this->decode($contents === false ? '' : $contents);
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
     */
    public function clearFleetEvents(): void
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
            if (! flock($handle, LOCK_EX)) {
                return;
            }

            $contents = stream_get_contents($handle);

            $kept = array_values(array_filter(
                $this->decode($contents === false ? '' : $contents),
                $this->isLocal(...)
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
        return substr(hash('sha256', implode("\0", [
            rtrim($this->service, '/'),
            $this->harness,
            $this->projectId ?? '',
        ])), 0, 32);
    }
}
