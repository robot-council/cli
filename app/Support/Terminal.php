<?php

declare(strict_types=1);

namespace App\Support;

/**
 * What may be written to a terminal from a value this command line did not compose.
 *
 * **Everything the service sends is such a value.** A `verification_uri`, a `user_code`, an `error`
 * string -- all arrive over the wire and all end up in front of a developer. A hostile or
 * compromised service can put ANSI escape sequences in any of them: to redraw the line so the code
 * a person reads is not the code they are approving, to hide output, or to fabricate a success
 * message under a failure.
 *
 * So nothing from the service reaches the terminal unreduced. This is the same reasoning
 * `robot-council/core` applies at its own edge, where `harness`, `machine_label` and `project_id`
 * are charset-limited because they reach other developers' agents -- one layer further out.
 */
final class Terminal
{
    /**
     * The longest a service-supplied string may be before it is cut.
     *
     * A URL or a code; anything longer is a service trying to fill a screen.
     */
    public const int MAX = 200;

    /**
     * One service-supplied value, safe to print.
     *
     * Control characters go, including ESC, CR, and the C1 range -- CR alone is enough to overwrite
     * a line a person has already read. Everything printable survives, so a legitimate URL with
     * query parameters or a percent-encoded path is untouched.
     *
     * @param  string  $value  Whatever the service sent.
     */
    public static function safe(string $value): string
    {
        $stripped = preg_replace('/[\x00-\x1F\x7F-\x9F]/u', '', $value);

        if (! \is_string($stripped)) {
            // `preg_replace` answers null on invalid UTF-8, which is itself a reason not to print
            // the value
            return '[unprintable]';
        }

        $stripped = trim($stripped);

        if ($stripped === '') {
            return '[empty]';
        }

        return mb_strlen($stripped) > self::MAX
            ? mb_substr($stripped, 0, self::MAX).'…'
            : $stripped;
    }
}
