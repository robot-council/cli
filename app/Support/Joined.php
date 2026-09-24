<?php

declare(strict_types=1);

namespace App\Support;

/**
 * What joining the fleet produced: the session, the follower reading its feed, and what to tell
 * the agent that asked.
 *
 * **Built by the command, handed to the bridge.** The bridge owns the protocol and the loop; the
 * command owns the credential, the checkout, and the sink, which is everything joining needs to
 * know about the machine it runs on (cli#127).
 */
final readonly class Joined
{
    /**
     * @param  Session  $session  The started session.
     * @param  FleetFollower  $follower  What reads this session's feed and fills its sink.
     * @param  string  $summary  What the `join` tool answers, for the agent to relay.
     */
    public function __construct(
        public Session $session,
        public FleetFollower $follower,
        public string $summary,
    ) {}
}
