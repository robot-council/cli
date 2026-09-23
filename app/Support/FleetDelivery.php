<?php

declare(strict_types=1);

namespace App\Support;

/**
 * What to tell somebody when nothing on their fleet can reach a waiting agent.
 *
 * **A fleet can be wired correctly and still deliver nothing.** `FleetFollower::ALWAYS` is
 * `['directive']`, posting a directive needs `coordinator:direct`, and enrollment can never request
 * it -- so unless an admin has granted it to some installation, every stop hook on that fleet finds
 * an empty sink forever. An empty sink and a fleet with nothing to say are byte-identical from the
 * agent's side, which is the absence this names (cli#113).
 *
 * **It is a separate class so the sentence can be tested.** The bridge that emits it runs an MCP
 * stdio loop that no test drives, so leaving the decision inline would have left it covered by a
 * skipped test -- which reads as coverage and is not. What remains uncovered is two lines of
 * wiring rather than the wording and the rule.
 */
final class FleetDelivery
{
    /**
     * The line to print, or null when there is nothing to say.
     *
     * **Only an explicit `false` is a finding.** `true` is a fleet that can deliver, and `null` is
     * a service that did not answer -- an older one predating `robot-council/core#159`, a refused
     * request, or a body that did not parse. Reporting unknown as "nothing will ever arrive" would
     * be a false alarm about the one thing this exists to report truthfully.
     *
     * **It says nothing about the session's own abilities, deliberately.** A bridge that only ever
     * receives holds no `coordinator:direct` and is correctly configured; that is the common case,
     * and warning on it would train people to ignore the line.
     *
     * **And it claims only what is gated, which an earlier version did not.** That version ended
     * "a stop hook here will never find anything waiting", and that is false: `FleetFollower`
     * delivers `lock.taken_over` and `lock.force_released` to the session a lease was taken FROM,
     * and an ordinary takeover needs only `locks:acquire`, which enrollment can request. Two agents
     * contending one lock on a fleet with no coordinator would have contradicted the sentence the
     * first time it happened -- and a warning a fleet refutes is worse than none, because the next
     * true one is read as noise. What is gated is the directive, so that is all this says.
     *
     * @param  bool|null  $fleetCanDirect  What the service answered, or null when it did not.
     * @return string|null The diagnostic, or null when there is nothing worth saying.
     */
    public static function warning(?bool $fleetCanDirect): ?string
    {
        if ($fleetCanDirect !== false) {
            return null;
        }

        return 'No installation on this fleet holds `coordinator:direct`, so no directive can be posted. '
            .'An admin can grant it with `php artisan robot-council:grant-ability`.';
    }
}
