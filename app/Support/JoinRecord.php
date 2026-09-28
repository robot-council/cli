<?php

declare(strict_types=1);

namespace App\Support;

use JsonException;

/**
 * What a Cursor bridge joined with, kept so that the bridge Cursor starts in its place can rejoin (#333).
 *
 * **Cursor restarts its MCP servers by itself**, and every stop ends the fleet session, so a Cursor
 * seat dropped off the fleet on every reload (#321). The maintainer allows one automatic join to
 * repair that: an application that had joined, rejoining after its bridge was replaced. This is
 * how a new bridge knows it is that application: the record names the parent process the join ran
 * under, by pid and start time, and a bridge under the same parent is the same application.
 *
 * **It outlives a harness stopping the bridge, and ends with the fleet ending the session.** A
 * reload is exactly a harness stop, so deleting the record there would defeat it. An
 * administrator removing the seat is the fleet's decision that it should be off, so that deletes
 * it (`Bridge`'s handling of `SessionHasGone`).
 *
 * Best-effort throughout: a record that cannot be written or read leaves a bridge that starts
 * unjoined, which is what every bridge did before this existed.
 */
final readonly class JoinRecord
{
    /**
     * @param  string  $path  Where the record lives, beside the sink (`PendingEvents::joinRecordPath()`).
     * @param  array{pid: int, started: string}|null  $parent  This bridge's parent, or null when it
     *                                                         could not be read.
     */
    public function __construct(
        private string $path,
        private ?array $parent,
    ) {}

    /**
     * Record a successful join, with what it joined with and the parent it ran under.
     *
     * @param  array{role: string|null, repository: string|null, work_location: string|null, capacity?: int|null}  $arguments  What the join used.
     */
    public function remember(array $arguments): void
    {
        $directory = \dirname($this->path);

        if (! is_dir($directory) && ! @mkdir($directory, 0o700, true) && ! is_dir($directory)) {
            return;
        }

        try {
            $encoded = json_encode([
                'role' => $arguments['role'],
                'repository' => $arguments['repository'],
                'work_location' => $arguments['work_location'],
                'capacity' => $arguments['capacity'] ?? null,
                'parent' => $this->parent,
                'joined_at' => time(),
            ], JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return;
        }

        // Written aside and moved into place, so a bridge starting mid-write never reads half a record
        $partial = $this->path.'.'.getmypid().'.tmp';

        if (@file_put_contents($partial, $encoded) === false) {
            return;
        }

        @chmod($partial, 0o600);

        if (! @rename($partial, $this->path)) {
            @unlink($partial);
        }
    }

    /**
     * What the last join recorded, or null when there is no record or it cannot be read.
     *
     * @return array{arguments: array{role: string|null, repository: string|null, work_location: string|null, capacity: int|null}, parent: array{pid: int, started: string}|null, joined_at: int}|null
     */
    public function recall(): ?array
    {
        $contents = @file_get_contents($this->path);

        if (! \is_string($contents)) {
            return null;
        }

        try {
            $decoded = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! \is_array($decoded) || ! \is_int($decoded['joined_at'] ?? null)) {
            return null;
        }

        $parent = $decoded['parent'] ?? null;

        return [
            'arguments' => [
                'role' => $this->stringOrNull($decoded['role'] ?? null),
                'repository' => $this->stringOrNull($decoded['repository'] ?? null),
                'work_location' => $this->stringOrNull($decoded['work_location'] ?? null),
                'capacity' => \is_int($decoded['capacity'] ?? null) ? $decoded['capacity'] : null,
            ],
            'parent' => \is_array($parent) && \is_int($parent['pid'] ?? null) && \is_string($parent['started'] ?? null)
                ? ['pid' => $parent['pid'], 'started' => $parent['started']]
                : null,
            'joined_at' => $decoded['joined_at'],
        ];
    }

    /**
     * Whether this bridge runs under the parent a record was made under.
     *
     * **Both halves, and both known.** An unreadable parent on either side is "not the same", since
     * the only thing this decides is whether to join without being asked.
     *
     * @param  array{pid: int, started: string}|null  $recorded  The parent the record names.
     */
    public function sameParent(?array $recorded): bool
    {
        return $recorded !== null
            && $this->parent !== null
            && $recorded['pid'] === $this->parent['pid']
            && $recorded['started'] === $this->parent['started'];
    }

    /**
     * Delete the record, because the fleet has ended the session it describes.
     */
    public function forget(): void
    {
        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }

    private function stringOrNull(mixed $value): ?string
    {
        return \is_string($value) ? $value : null;
    }
}
