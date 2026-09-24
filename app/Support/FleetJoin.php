<?php

declare(strict_types=1);

namespace App\Support;

use App\Support\Credentials\Credentials;
use App\Support\Credentials\InstallationChoice;
use Illuminate\Http\Client\Factory;
use RuntimeException;
use Throwable;

/**
 * Joining the fleet: choose the credential, start the session, ask for a role, open the sink.
 *
 * **Its own class so its rules can be tested (cli#127).** The bridge calls it through a closure,
 * and a test that swaps the closure out tests the bridge and none of this: which argument wins,
 * what a failed role request leaves behind, and that a join reporting failure leaves no session.
 */
final class FleetJoin
{
    /**
     * The sink the joined session's follower writes, once a join has succeeded.
     */
    private ?PendingEvents $pending = null;

    /**
     * @param  Factory  $http  The HTTP client.
     * @param  Credentials  $credentials  Where the installation credential lives.
     * @param  string  $service  The service's base URL.
     * @param  string  $harness  Which harness this process is, for the sink's key.
     * @param  string|null  $harnessFlag  What `--harness` said, for choosing the credential.
     * @param  string|null  $project  What `--project` said.
     * @param  string|null  $repository  What `--repository` said.
     * @param  string|null  $workLocation  What `--work-location` said.
     * @param  callable(string):void  $diagnostic  Where operator-facing lines go.
     * @param  string|null  $directory  The checkout to read, for a test; the working directory otherwise.
     */
    public function __construct(
        private readonly Factory $http,
        private readonly Credentials $credentials,
        private readonly string $service,
        private readonly string $harness,
        private readonly ?string $harnessFlag,
        private readonly ?string $project,
        private readonly ?string $repository,
        private readonly ?string $workLocation,
        private $diagnostic,
        private readonly ?string $directory = null,
    ) {}

    /**
     * Join.
     *
     * **A session that started is ended if anything after it fails**, so a join that reports
     * failure never leaves a session behind it on the fleet.
     *
     * @param  array{role: string|null, repository: string|null, work_location: string|null}  $arguments  What `join` was given.
     * @return Joined The started session and its follower.
     *
     * @throws RuntimeException With a sentence for the agent, when joining failed.
     */
    public function __invoke(array $arguments): Joined
    {
        // One credential per harness per fleet, so this process has to say which harness it is.
        // It refuses rather than guessing, including when only one is stored -- #21 weighed that
        // and chose one rule with nothing inferred. `InstallationChoice` throws a sentence naming
        // what is missing, which is what `join` hands the agent.
        $installation = new InstallationChoice($this->credentials)->for($this->service, $this->harnessFlag);

        $session = new Session($this->http, $this->service, $installation);

        // What `join` was told wins; then the flags; then the checkout this process runs in, so 37
        // hand-maintained config entries stop needing to be maintained. Per field, and neither may
        // keep the session from starting.
        $givenRepository = $arguments['repository'] ?? $this->repository;
        $givenLocation = $arguments['work_location'] ?? $this->workLocation;

        [$repository, $workLocation] = Checkout::resolve($givenRepository, $givenLocation, $this->directory);

        $notes = array_values(array_filter([
            $this->refusal('repository', $givenRepository, $repository),
            $this->refusal('work location', $givenLocation, $workLocation),
        ]));

        foreach ($notes as $note) {
            ($this->diagnostic)($note);
        }

        try {
            $session->start($this->project, $repository, $workLocation);
        } catch (RuntimeException $runtimeException) {
            throw $runtimeException;
        } catch (Throwable) {
            throw new RuntimeException('The fleet could not be reached.');
        }

        try {
            $role = $arguments['role'];

            if ($role !== null && $role !== 'build') {
                $notes[] = $this->requestRole($session, $role);
            }

            // The sink the follower writes and `robot-council pending` drains, keyed by the same
            // three things that identify this bridge, so a stop hook beside it finds the same file.
            $pending = new PendingEvents($this->service, $this->harness, $this->project);

            $follower = new FleetFollower($session, $this->service, $pending);

            // **Said at join, and only when the answer is a definite no.** A fleet can be wired
            // correctly and still deliver nothing, because posting a directive needs the
            // `coordinator` role and no session starts with it (cli#113). Still on stderr, never
            // on stdout.
            $delivery = FleetDelivery::warning($session->fleetCanDirect());

            if ($delivery !== null) {
                ($this->diagnostic)($delivery);
            }
        } catch (Throwable $throwable) {
            $session->end();

            throw new RuntimeException($throwable instanceof RuntimeException
                ? $throwable->getMessage().' The session that had started was ended.'
                : 'Joining failed after the session started, so it was ended.', $throwable->getCode(), $throwable);
        }

        $this->pending = $pending;

        $summary = \sprintf(
            "Joined the fleet as session %s%s. The fleet's tools are available now.",
            $session->id() ?? 'unknown',
            $repository === null ? '' : \sprintf(', working in %s%s', $repository, $workLocation === null ? '' : ' at '.$workLocation)
        );

        return new Joined($session, $follower, implode(' ', [$summary, ...$notes]));
    }

    /**
     * The sink a successful join opened, for the command to clear on the way out.
     */
    public function pending(): ?PendingEvents
    {
        return $this->pending;
    }

    /**
     * Ask for a role, and say in a sentence what came of it.
     *
     * **Two calls, not atomic, and the gap is reported rather than hidden.** A session that started
     * and then failed to ask for its role is live on `build`, which holds less than was asked for and
     * never more; `join` still says so instead of reporting a plain success. Every failure, a refusal
     * or a dropped connection alike, lands here rather than ending the session.
     */
    private function requestRole(Session $session, string $role): string
    {
        try {
            $answer = $session->requestRole($role);
        } catch (Throwable $throwable) {
            $reason = $throwable instanceof RuntimeException ? ' '.$throwable->getMessage() : ' The fleet could not be reached.';

            return \sprintf('It joined as build: asking for %s failed.%s', $role, $reason);
        }

        if ($answer['pending']) {
            return \sprintf("It asked for the %s role; an administrator decides, and until then it holds %s's abilities.", $role, $answer['role'] ?? 'build');
        }

        // Nothing to decide: either it holds the role already, or the fleet recorded no request
        return $answer['role'] === $role
            ? \sprintf('It holds the %s role already.', $role)
            : \sprintf('It asked for the %s role, and the fleet recorded no request; it holds %s.', $role, $answer['role'] ?? 'an unknown role');
    }

    /**
     * A sentence saying a value was given and could not be used, or null when there is nothing to say.
     *
     * **Silence would be the worst outcome here.** Somebody wrote this value out, and dropping it
     * without a word gives them a session that is missing it and no reason to look -- which is the
     * label that stays wrong for months because nothing ever contradicts it.
     */
    private function refusal(string $field, ?string $given, ?string $resolved): ?string
    {
        if ($given === null || $resolved !== null) {
            return null;
        }

        return \sprintf('The %s %s is not a shape the fleet accepts, so it was left unset.', $field, $given);
    }
}
