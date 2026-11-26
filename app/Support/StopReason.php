<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Which end of the bridge's loop ran, as the record of a stop names it (#396).
 *
 * A closed list, because a free-form reason is a place for an exception's message to land, and a
 * message can carry a token. The class name and an HTTP status are enough to tell these apart.
 */
enum StopReason: string
{
    /**
     * The bridge's input ended. The record's `reader` fact says how: `exited-0` is the reader child
     * seeing the harness close stdin, which is the ordinary case; any other exit code is the child
     * failing; `running` is the socket ending while the child lived, which is unexpected.
     */
    case StdinClosed = 'stdin-closed';

    /**
     * The renewal answered `409`: the fleet marked this session gone (`SessionHasGone`).
     */
    case SessionGone = 'session-gone';

    /**
     * `SIGTERM` or `SIGINT` reached the process. Never recorded on Windows, where PHP has no
     * signal handling, so a bridge ended there by the harness shows up as `stdin-closed` or as no
     * line at all.
     */
    case Signal = 'signal';

    /**
     * Something escaped the loop; the line carries the class name and nothing else.
     */
    case Unhandled = 'unhandled-exception';

    /**
     * The loop was stopped and nothing said why.
     */
    case Other = 'other';

    /**
     * The bridge returned before it could start: no service URL, or the stdin reader would not start
     * (#403). There is no reader and no session on the line, and a throwable contributes its class
     * name only.
     */
    case StartFailed = 'start-failed';

    /**
     * Which reason a stop is recorded under.
     *
     * **A throwable that escaped wins over what the bridge noted**, because the loop may have been
     * stopped for one reason and then failed on the way out, and the failure is the news.
     *
     * @param  string|null  $escaped  The class of a throwable that left the loop, when one did.
     * @param  self|null  $noted  What the bridge itself recorded as the end of its loop.
     */
    public static function recordedFor(?string $escaped, ?self $noted): self
    {
        if ($escaped !== null) {
            return self::Unhandled;
        }

        return $noted ?? self::Other;
    }
}
