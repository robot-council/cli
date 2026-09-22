<?php

declare(strict_types=1);

namespace App\Support;

use Laravel\AgentDetector\AgentDetector;
use Laravel\AgentDetector\KnownAgent;

/**
 * What this machine calls itself, reduced to what the service will store.
 *
 * **`robot-council/core` is the authority on both bounds, and this mirrors them deliberately.** Its
 * `Support\MachineIdentity` refuses a harness outside `[a-z0-9-]` at 32 characters, and a label
 * outside `[A-Za-z0-9._-]` at 64. A value this command line sends that the service then refuses is
 * a bug here, not there: a developer should not have to discover that their hostname contains a
 * character an unfamiliar service dislikes.
 *
 * The two charsets differ on purpose. A harness names a piece of software, so it is lower case and
 * two spellings would read as two harnesses across the fleet. A label is what a person calls their
 * laptop, so it keeps case, `.` and `_`.
 */
final class MachineIdentity
{
    /**
     * The environment variable a harness names itself in.
     */
    public const string VARIABLE = 'ROBOT_COUNCIL_HARNESS';

    /**
     * The longest harness name the service stores.
     */
    public const int MAX_HARNESS = 32;

    /**
     * The longest machine label the service stores.
     */
    public const int MAX_LABEL = 64;

    /**
     * What the harness this is running under calls itself, or null when nothing recognizes it.
     *
     * Read from `laravel/agent-detector`'s enum **value**, never its `label()`. The values are
     * already within the service's harness charset -- `claude`, `codex`, `augment-cli` -- while
     * `label()` returns display text like `Augment CLI`, which the service would refuse for both
     * its space and its capitals.
     */
    public static function detectedHarness(): ?string
    {
        $agent = AgentDetector::detect()->knownAgent();

        return $agent?->value;
    }

    /**
     * Every harness `laravel/agent-detector` can name, as the service would store them.
     *
     * Used to say what a machine has enrolled when it refuses to guess. Read from the enum's
     * **values**, which are already inside the service's harness charset, never from `label()`,
     * which returns display text like `Augment CLI`.
     *
     * This is a list of what is *knowable*, not of what a developer may use: `--harness=whatever`
     * is accepted if it fits the charset, so anything reported from this list is a lower bound.
     *
     * @return list<string> The harness names.
     */
    public static function knownHarnesses(): array
    {
        return array_map(
            static fn (KnownAgent $agent): string => $agent->value,
            KnownAgent::cases()
        );
    }

    /**
     * The harness a person named, from a flag or from the environment.
     *
     * Told apart from a detected one because `Credentials\InstallationChoice` treats them
     * differently: only an explicitly named harness may claim a credential stored before harnesses
     * were told apart, since a bare-fleet entry belongs to whichever harness enrolled it and
     * adopting it on a detected name would be the inference #21 refused.
     *
     * @param  string|null  $flag  What `--harness` carried, when it carried anything.
     * @return string|null The harness, or null when nobody said.
     */
    public static function namedHarness(?string $flag): ?string
    {
        if (\is_string($flag) && $flag !== '') {
            return self::harness($flag);
        }

        $fromEnvironment = getenv(self::VARIABLE);

        return \is_string($fromEnvironment) && trim($fromEnvironment) !== ''
            ? self::harness(trim($fromEnvironment))
            : null;
    }

    /**
     * Which harness this process is, by the whole cascade.
     *
     * **One definition, because two would drift and the drift would be silent** (cli#60). The
     * bridge picks a credential by this, and `pending` finds that bridge's sink by it: a `pending`
     * that resolved the harness differently would read an empty sink beside a full one and report
     * a quiet fleet.
     *
     * @param  string|null  $flag  What `--harness` carried, when it carried anything.
     * @return string|null The harness, or null when nothing answered.
     */
    public static function resolveHarness(?string $flag): ?string
    {
        return self::namedHarness($flag) ?? self::detectedHarness();
    }

    /**
     * A harness name the service will accept, or null when nothing usable is left.
     *
     * Lower-cased first, so that `Claude` from a `--harness` flag becomes the same harness the
     * detector would have reported rather than a second one.
     */
    public static function harness(string $harness): ?string
    {
        $reduced = preg_replace('/[^a-z0-9-]/', '', strtolower($harness)) ?? '';

        $reduced = substr($reduced, 0, self::MAX_HARNESS);

        return $reduced === '' ? null : $reduced;
    }

    /**
     * A label for this machine the service will accept.
     *
     * Falls back to `unknown-machine` rather than failing: a hostname made entirely of characters
     * outside the charset is unusual but not a reason to refuse enrollment, and a developer can
     * pass `--machine-label` to say something better.
     */
    public static function label(?string $preferred = null): string
    {
        $candidate = $preferred ?? gethostname();

        if (! \is_string($candidate)) {
            return 'unknown-machine';
        }

        // `.local`, which macOS appends to every hostname, is noise on a fleet where every machine
        // would carry it
        $candidate = preg_replace('/\.local$/', '', $candidate) ?? $candidate;

        $reduced = preg_replace('/[^A-Za-z0-9._-]/', '', $candidate) ?? '';

        $reduced = substr($reduced, 0, self::MAX_LABEL);

        return $reduced === '' ? 'unknown-machine' : $reduced;
    }
}
