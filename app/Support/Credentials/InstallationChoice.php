<?php

declare(strict_types=1);

namespace App\Support\Credentials;

use App\Support\MachineIdentity;
use RuntimeException;

/**
 * Which stored credential this process should present.
 *
 * One machine holds one credential per harness per fleet (`robot-council/cli#21`), so a process
 * that is about to speak for a machine has to say which harness it is. It is asked in this order:
 *
 * 1. `--harness=` on the command line
 * 2. `ROBOT_COUNCIL_HARNESS` in the environment
 * 3. `laravel/agent-detector`
 * 4. nothing -- and then it refuses
 *
 * **There is no case where a single stored credential is used because it was the only one.**
 * #21 weighed that against one rule with nothing inferred and chose the rule. It costs a machine
 * where detection does not fire an explicit harness, and it means a machine that works today
 * refuses after this ships until it names one. That was accepted knowing the cost.
 *
 * **The environment variable carries more weight than detection**, not less. A harness's MCP
 * configuration already sets `ROBOT_COUNCIL_SERVICE` in its `env` block, so naming the harness
 * beside it introduces no new mechanism and does not depend on detection working in that harness --
 * which for Cursor is unmeasured.
 */
final class InstallationChoice
{
    /**
     * The environment variable a harness's configuration names its harness in.
     */
    public const string VARIABLE = 'ROBOT_COUNCIL_HARNESS';

    /**
     * @param  Credentials  $credentials  Where the credentials are.
     */
    public function __construct(private readonly Credentials $credentials) {}

    /**
     * The credential for this fleet and this harness.
     *
     * @param  string  $service  The fleet's base URL.
     * @param  string|null  $flag  What `--harness` said, if anything.
     * @return Credential The credential to present.
     *
     * @throws RuntimeException When no harness resolves, when none is stored for the one that did,
     *                          or when the credential store itself could tell that its mechanism
     *                          had failed. Every message is written for a developer mid-wire-up
     *                          and is safe to print: they name what is stored, never what is in it.
     *                          The third is why `remedies()` swallows a store failure -- a
     *                          diagnostic that aborts the diagnosis is worse than a short one.
     */
    public function for(string $service, ?string $flag): Credential
    {
        $named = $this->named($flag);
        $harness = $named ?? MachineIdentity::detectedHarness();

        if ($harness === null) {
            throw new RuntimeException($this->unresolved($service));
        }

        $credential = $this->credentials->get($service, $harness);

        if ($credential instanceof Credential) {
            return $credential;
        }

        // **Only an explicitly named harness claims a legacy credential.** A bare-fleet entry
        // belongs to whichever harness enrolled it, which this process cannot know, so adopting it
        // on a detected name would be the inference #21 refused arriving by a side door. A flag or
        // an environment variable is a person saying which it was.
        $legacyRuledOut = false;

        if ($named !== null) {
            $adopted = $this->credentials->adopt($service, $named);

            if ($adopted instanceof Credential) {
                return $adopted;
            }

            // **Reaching here is itself the answer about the legacy key.** `adopt()` begins by
            // reading it, and claims it when it is there -- so a null return means it looked and
            // found nothing. A raise would have left through this call rather than arriving here.
            // Carrying that spares the second read the refusal used to make, and, more than the
            // subprocess, stops the two halves of one refusal being computed from two answers.
            $legacyRuledOut = true;
        }

        throw new RuntimeException($this->missing($service, $harness, $legacyRuledOut));
    }

    /**
     * The harness a person named, from the flag or the environment.
     *
     * Reduced through `MachineIdentity::harness()` so that `Claude` and `claude` are one harness
     * rather than two, exactly as `enroll` reduces what it was given.
     *
     * @param  string|null  $flag  What `--harness` said, if anything.
     * @return string|null The harness, or null when nobody named one.
     */
    private function named(?string $flag): ?string
    {
        // Delegated, so this and `pending` cannot disagree about which harness a process is: the
        // bridge picks a credential by that answer and `pending` finds the bridge's sink by it, and
        // a disagreement would be an empty sink beside a full one (cli#60).
        return MachineIdentity::namedHarness($flag);
    }

    /**
     * What to say when nothing named a harness.
     *
     * @param  string  $service  The fleet's base URL.
     * @return string The message.
     */
    private function unresolved(string $service): string
    {
        return 'Could not tell which harness this is, and a credential belongs to one. '
            .$this->remedies($service);
    }

    /**
     * What to say when a harness resolved and has nothing stored.
     *
     * @param  string  $service  The fleet's base URL.
     * @param  string  $harness  The harness that resolved.
     * @param  bool  $legacyRuledOut  Whether the legacy key has already been read on this path and
     *                                held nothing, which is true exactly when `adopt()` ran and
     *                                returned null.
     * @return string The message.
     */
    private function missing(string $service, string $harness, bool $legacyRuledOut): string
    {
        return sprintf('This machine is not enrolled as `%s` against that fleet. ', $harness)
            .$this->remedies($service, $legacyRuledOut);
    }

    /**
     * The two ways out, and what is actually stored.
     *
     * **The stored harnesses are probed rather than listed, because no store can list its keys.**
     * Asking for each harness the detector knows about is a bounded number of reads -- and on a
     * keychain that is a subprocess each -- so it happens only here, on a path where the process is
     * already refusing. A harness named by hand that the detector has never heard of will not
     * appear, which the wording allows for.
     *
     * **The legacy key is read at most once per refusal.** `adopt()` reads it on the named-harness
     * path, and this method used to read it again to write the message -- two subprocesses on a
     * keychain, on a path already failing, where the second could return nothing the first did not.
     * Worse than the cost: the two reads could **disagree**, because `adopt()` lets a
     * `CredentialStoreFailed` out while this method catches one and reports the entry absent. A
     * store breaking between them produced a refusal whose halves were computed against different
     * answers, with nothing in the message saying so (#93).
     *
     * @param  string  $service  The fleet's base URL.
     * @param  bool  $legacyRuledOut  Whether the caller already read the legacy key and found
     *                                nothing. False means nobody has looked -- which is the
     *                                `unresolved()` path, reached before any read happens -- and
     *                                this method makes the one read itself.
     * @return string The remedies.
     */
    private function remedies(string $service, bool $legacyRuledOut = false): string
    {
        $stored = $this->credentials->storedAmong($service, MachineIdentity::knownHarnesses());

        if ($stored !== []) {
            return sprintf(
                "Enrolled here: %s. Pass --harness=<name>, or set %s in this harness's configuration.",
                implode(', ', $stored),
                self::VARIABLE
            );
        }

        // Swallowed for the same reason `storedAmong()` swallows its own: this runs while the
        // process is already refusing, and a store that has broken since the command started must
        // not replace a refusal that names a remedy with one that names none. A legacy credential
        // that cannot be read is reported as absent, which sends the operator to `enroll` -- the
        // right advice whether the entry is missing or unreadable.
        if ($legacyRuledOut) {
            return 'Run `robot-council enroll` first.';
        }

        try {
            $legacy = $this->credentials->legacy($service) instanceof Credential;
        } catch (CredentialStoreFailed) {
            $legacy = false;
        }

        if ($legacy) {
            return 'A credential stored before harnesses were told apart is here. '
                .'Pass --harness=<the harness it was enrolled as> to claim it, or run `robot-council enroll` again.';
        }

        return 'Run `robot-council enroll` first.';
    }
}
