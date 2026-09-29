<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * A sink that exists, or should, and could not be read (cli#345).
 *
 * **Not the same answer as an empty sink, and that is the whole point.** A stop hook and an agent
 * both read empty output as "the fleet has been quiet", so a read that failed and returned nothing
 * left the events waiting with nothing to say why: measured on a saturated machine, `pending --peek`
 * printed nothing and exited 0 while the sink held an event. The message says what failed, for the
 * operator and the agent.
 */
final class UnreadableSink extends RuntimeException {}
