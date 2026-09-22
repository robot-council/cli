<?php

declare(strict_types=1);

namespace App\Support;

use Throwable;

/**
 * Reads the fleet's change feed and leaves what concerns this session where a hook can find it.
 *
 * **Every path into the fleet is agent-initiated, so an agent that is not running a turn learns
 * nothing.** A directive, a hand-back, or a lock being force-released sits in the feed until
 * something causes that agent to read it, and the interval is unbounded rather than slow, because
 * nothing schedules the next read (cli#59, cli#60).
 *
 * It lives in the bridge because the bridge already has everything: a loop that ticks every five
 * seconds, the credential, the session, and the feed cursor the service handed back when the
 * session started. A separate long-running command would need a second credential presentation and
 * would start a second session, which the fleet would list as an extra agent.
 *
 * **What it does not do is wake anybody.** It makes the news available at a turn boundary; the
 * harness's stop hook is what hands it to the agent (cli#61).
 */
final class FleetFollower
{
    /**
     * How long to wait between reads of the feed, in seconds.
     *
     * Six requests a minute against the 120 a minute `robot-council.rate_limits.agent_per_session`
     * allows, with the bridge's own heartbeat taking one more. The interval is not latency: nothing
     * acts on what this finds until the agent finishes a turn.
     */
    public const int POLL_SECONDS = 10;

    /**
     * How long to wait before reading again after a failure, and the ceiling it backs off to.
     *
     * The same shape the bridge's token renewal uses, and for the reason that one records: retrying
     * on every pass was measured at ten attempts a minute while idle, which can cross the service's
     * own rate limit and lock an installation out of the thing it was trying to do.
     */
    public const int BACKOFF_SECONDS = 30;

    public const int MAX_BACKOFF_SECONDS = 300;

    /**
     * The event types that always concern a session, whoever they name.
     */
    private const array ALWAYS = ['directive'];

    /**
     * Where the feed has been read to.
     */
    private ?int $cursor;

    /**
     * When the feed may next be read, as a Unix timestamp.
     */
    private int $nextPoll = 0;

    /**
     * How long the next failure waits, in seconds.
     */
    private int $backoff = self::BACKOFF_SECONDS;

    /**
     * The tasks this session is holding, as far as the feed has said.
     *
     * **Derived from the feed rather than looked up**, because `task.reassigned` and `task.cancelled`
     * record the task, the new status and the new assignee -- and not whom the task was taken from.
     * The set is complete anyway: a session can only hold a task it claimed or was handed during its
     * own life, and the cursor starts at that session's own enrollment, so both arrive here.
     *
     * @var array<int, true>
     */
    private array $held = [];

    /**
     * @param  Session  $session  The started session whose feed this reads.
     * @param  string  $service  The service's base URL.
     * @param  PendingEvents  $pending  Where matching events are left.
     * @param  int  $pollSeconds  How long to wait between reads.
     */
    public function __construct(
        private readonly Session $session,
        private readonly string $service,
        private readonly PendingEvents $pending,
        private readonly int $pollSeconds = self::POLL_SECONDS
    ) {
        $this->cursor = $session->feedCursor();
    }

    /**
     * Read the feed if it is due, and leave anything that concerns this session.
     *
     * **Never throws and never writes to stdout.** A bridge whose follower failed must go on
     * forwarding tool calls: the feed is an extra, and a fleet that cannot be read is not a reason
     * to stop answering the agent in front of you.
     *
     * @param  callable(string):void  $diagnostic  Where anything that is not a protocol message goes.
     */
    public function tick(callable $diagnostic): void
    {
        if ($this->cursor === null || time() < $this->nextPoll) {
            return;
        }

        try {
            $page = $this->read();
        } catch (Throwable $throwable) {
            $this->nextPoll = time() + $this->backoff;

            $this->backoff = min($this->backoff * 2, self::MAX_BACKOFF_SECONDS);

            $diagnostic('Could not read the fleet feed: '.$throwable->getMessage());

            return;
        }

        $this->backoff = self::BACKOFF_SECONDS;
        $this->nextPoll = time() + $this->pollSeconds;

        // The cursor the service returns, not the last event's id: a page can be short or empty
        // because of the visibility rule, and a reader still has to make progress.
        $this->cursor = $page['cursor'] ?? $this->cursor;

        $concerning = [];

        foreach ($page['events'] as $event) {
            // **Decided before the held set is updated, and the order is the whole of it.** A
            // `task.cancelled` for a task this session holds both concerns it AND ends the hold, so
            // tracking first would drop the task from the set and then ask whether it was in it --
            // and the answer would be no, silently, for exactly the events worth reporting.
            if ($this->concerns($event)) {
                $concerning[] = $event;
            }

            // Then the set, from every event including ones that do not concern this session:
            // a task handed away by somebody else still leaves this session's hold.
            $this->track($event);
        }

        if ($concerning === []) {
            return;
        }

        try {
            $this->pending->add($concerning);
        } catch (Throwable $throwable) {
            $diagnostic($throwable->getMessage());
        }
    }

    /**
     * Read the next page of the feed.
     *
     * @return array{events: list<array<array-key, mixed>>, cursor: int|null} The page.
     */
    private function read(): array
    {
        $response = $this->session->request()
            ->get($this->service.'/robot-council/api/events', ['after' => $this->cursor]);

        if (! $response->successful()) {
            throw new \RuntimeException('the service answered '.$response->status().'.');
        }

        $body = $response->json();

        if (! \is_array($body)) {
            throw new \RuntimeException('the service answered with something that is not a page.');
        }

        $events = $body['events'] ?? [];
        $cursor = $body['cursor'] ?? null;

        return [
            'events' => \is_array($events) ? array_values(array_filter($events, \is_array(...))) : [],
            'cursor' => \is_int($cursor) ? $cursor : $this->cursor,
        ];
    }

    /**
     * Update what this session is holding, from one event.
     *
     * @param  array<array-key, mixed>  $event  One event from the feed.
     */
    private function track(array $event): void
    {
        $taskId = $this->taskId($event);

        if ($taskId === null) {
            return;
        }

        $type = $this->type($event);

        if (\in_array($type, ['task.claimed', 'task.reassigned'], true)) {
            $this->assignedTo($event) === $this->session->id()
                ? $this->held[$taskId] = true
                : $this->forget($taskId);

            return;
        }

        // Anything that ends a task ends this session's hold on it, whoever did it.
        if (\in_array($type, ['task.released', 'task.cancelled', 'task.completed', 'task.failed'], true)) {
            $this->forget($taskId);
        }
    }

    /**
     * Whether an event is one this session should be told about.
     *
     * **An event this session authored is never one.** A session's own `task.claimed` reaches its
     * own feed, so without this every tool call the agent makes would queue a wake-up for the agent
     * that made it.
     *
     * @param  array<array-key, mixed>  $event  One event from the feed.
     */
    private function concerns(array $event): bool
    {
        if ($this->actor($event) === $this->session->id()) {
            return false;
        }

        $type = $this->type($event);

        if (\in_array($type, self::ALWAYS, true)) {
            return true;
        }

        // Work handed to this session, by somebody else.
        if (\in_array($type, ['task.claimed', 'task.reassigned'], true)
            && $this->assignedTo($event) === $this->session->id()) {
            return true;
        }

        // A task this session is holding, moved by somebody else.
        if (\in_array($type, ['task.reassigned', 'task.cancelled'], true)) {
            $taskId = $this->taskId($event);

            if ($taskId !== null && isset($this->held[$taskId])) {
                return true;
            }
        }

        // A lease this session held, taken from it.
        if (\in_array($type, ['lock.taken_over', 'lock.force_released'], true)
            && $this->metaInt($event, 'taken_from') === $this->session->id()) {
            return true;
        }

        // This session's own presence, decided by the sweep rather than by it.
        return \in_array($type, ['session.stale', 'session.gone'], true)
            && $this->sessionId($event) === $this->session->id();
    }

    /**
     * Stop counting a task as held.
     */
    private function forget(int $taskId): void
    {
        unset($this->held[$taskId]);
    }

    /**
     * One event's type.
     *
     * @param  array<array-key, mixed>  $event  The event.
     */
    private function type(array $event): string
    {
        return \is_string($event['type'] ?? null) ? $event['type'] : '';
    }

    /**
     * The session that posted an event, when one did.
     *
     * @param  array<array-key, mixed>  $event  The event.
     */
    private function actor(array $event): ?int
    {
        $actor = $event['actor'] ?? null;

        if (! \is_array($actor)) {
            return null;
        }

        return \is_int($actor['session_id'] ?? null) ? $actor['session_id'] : null;
    }

    /**
     * The session an event is about, which for a presence event is its subject.
     *
     * @param  array<array-key, mixed>  $event  The event.
     */
    private function sessionId(array $event): ?int
    {
        return $this->actor($event);
    }

    /**
     * The task an event names, when it names one.
     *
     * @param  array<array-key, mixed>  $event  The event.
     */
    private function taskId(array $event): ?int
    {
        return $this->metaInt($event, 'task_id');
    }

    /**
     * The session a task was handed to, when an event names one.
     *
     * @param  array<array-key, mixed>  $event  The event.
     */
    private function assignedTo(array $event): ?int
    {
        return $this->metaInt($event, 'assigned_to');
    }

    /**
     * One integer out of an event's `meta`.
     *
     * @param  array<array-key, mixed>  $event  The event.
     * @param  string  $key  The key to read.
     */
    private function metaInt(array $event, string $key): ?int
    {
        $meta = $event['meta'] ?? null;

        if (! \is_array($meta)) {
            return null;
        }

        $value = $meta[$key] ?? null;

        return \is_int($value) ? $value : null;
    }
}
