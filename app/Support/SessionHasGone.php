<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * The fleet has marked this session `gone`, which is final.
 *
 * `robot-council/core`'s `Support\SessionPresence` refuses the session's tokens, releases its
 * claims and drops its locks, and nothing lifts it again -- `movesFrom(Active)` is `[Stale]`, so
 * `resume()` can never reach a gone row. Every request the bridge makes from that moment fails.
 *
 * **Distinguished from an ordinary renewal failure because only this one is terminal.** A 500 or a
 * timeout means try again in a moment; a 409 means there is nothing left to try. `Session::renew()`
 * collapsed every non-2xx into one message, so the bridge could not tell "the service is having a
 * bad minute" from "this session no longer exists" -- and treated both as the first.
 *
 * **The feed cannot deliver this news, which is why it is an exception rather than an event.** Core
 * writes the `session.gone` event, flips the status and deletes the tokens in a single
 * `DB::transaction`, and `Http\Middleware\EnsureAgentSession` refuses a gone session outright --
 * `GET events` sits behind it. So at no instant can a session read the event announcing its own
 * end: the read is a 401 from the moment the event exists. The renewal endpoint is the one that
 * still answers, because it takes the installation credential rather than the session token, and it
 * says `409` for exactly and only this case.
 */
final class SessionHasGone extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The fleet has marked this session gone, so its claims and locks have been released and its token will be refused. Stopping. Nothing is wrong with the installation, and a new session needs a new bridge.');
    }
}
