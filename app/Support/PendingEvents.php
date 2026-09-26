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
     * Take the sink's lock without blocking, retrying until the wait runs out.
     *
     * Counted in attempts rather than against a clock, so a test that fakes `Sleep` sees the same
     * schedule a real run does: a first try, then one after each sleep, until the sleeps add up to
     * `CLEAR_WAIT_MILLISECONDS`.
     *
     * @param  resource  $handle  The open sink.
     * @return bool Whether the lock was taken.
     */
    private function lockWithinTheWait(mixed $handle): bool
    {
        for ($waited = 0; ; $waited += self::CLEAR_RETRY_MILLISECONDS) {
            if (flock($handle, LOCK_EX | LOCK_NB)) {
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
        if ($this->projectId === null && $this->harness !== self::APP_WIDE_HARNESS) {
            $parts[] = $this->checkout();
        }

        return substr(hash('sha256', implode("\0", $parts)), 0, 32);
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

        for ($attempt = 0; $attempt < self::CHECKOUT_ATTEMPTS; $attempt++) {
            $resolved = Checkout::resolveGitDirectory($directory === '' ? null : $directory);

            if ($resolved !== null) {
                return self::$checkouts[$directory] = $this->comparable($resolved === false ? $this->realDirectory($directory) : $resolved);
            }
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
