<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Domain\Fleet\FleetConvergeUnits;
use App\Domain\GatewayReleases\DeployedGatewayRelease;
use App\Domain\GatewayReleases\GatewayReleaseCommit;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\GatewayReleases\GatewayReleaseRuntime;
use App\Domain\GatewayReleases\GatewayReleaseSmoke;
use App\Domain\GatewayReleases\GatewayReleaseVerifier;
use App\Domain\GatewayReleases\GatewayReleaseWebBuild;
use App\Models\GatewayRelease;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Switches to a prepared release, hands the runtime over, verifies, switches the web app, then
 * runs smoke. Verify and smoke see the new release. Smoke runs only after the web switch, and it
 * checks that the scheduler and agent view started after the handoff began.
 *
 * Any failure after the switch, expected or not, is handled the same way: without migrations the
 * previous release becomes current again and its runtime handoff repeats; after migrations the
 * release pauses. A failure after migrations always pauses, also when the switch itself failed,
 * because the previous code then serves a schema it has not run. Every outcome is recorded, and
 * each step is stored on the release record as it ends.
 */
final readonly class GatewayReleasePromoter
{
    /**
     * The default for the most releases kept, the current and the previous one included. The rest of the room goes to
     * the newest other releases. It is at least 2, so the previous release stays as the way back.
     */
    public const int KeptReleases = 3;

    /**
     * Pruning removes a release directory without `REVISION` once it is this many seconds old. Every prepare runs
     * under the release lock that pruning holds too, so no prepare writes to it; the age is a margin on top.
     */
    public const int IncompleteReleaseSeconds = 3600;

    /** The command `orbit-fleet-converge.service` runs, relative to a release's Gateway application. */
    public const string FleetCommand = 'app/Console/Commands/FleetConvergeCommand.php';

    public function __construct(
        private GatewayReleaseLayout $layout,
        private GatewayReleaseSwitcher $switcher,
        private GatewayReleaseRuntime $runtime,
        private GatewayReleaseVerifier $verifier,
        private GatewayReleaseWebBuild $web,
        private GatewayReleaseSmoke $smoke,
        private GatewayReleaseRecorder $recorder,
        private GatewayReleaseBuilder $builder,
        /** Checks the previous release against the applied schema before a switch-back. */
        private GatewayReleaseGuard $guard,
        private int $keptReleases = self::KeptReleases,
        private GatewayReleaseRetry $retry = new GatewayReleaseRetry,
        private ?FleetConvergeUnits $fleet = null,
        private ?GatewayReleaseTickConfirmation $ticks = null,
    ) {}

    /**
     * @param  array<string, mixed>  $phases
     */
    public function promote(
        string $id,
        string $sha,
        string $trigger,
        bool $migrationsRan,
        ?string $snapshotPath,
        array $phases,
        int $startedAt,
        ?GatewayRelease $record = null,
    ): DeployedGatewayRelease {
        $previous = $this->layout->currentReleaseId();
        $scheduled = null;
        $scheduleStarted = false;
        $step = 'configuration';

        try {
            if (! isset($phases['configuration'])) {
                $this->builder->refreshConfiguration($id);
                $phases['configuration'] = ['outcome' => 'cached'];
            }

            $this->recorder->progress($record, $phases, $sha);

            $step = 'switch';
            $previous = $this->switcher->switchTo($id);
            $phases['switch'] = ['outcome' => 'switched', 'from' => $previous, 'to' => $id];
            $this->recorder->progress($record, $phases);
            $step = 'handoff';
            $handoffAt = CarbonImmutable::now('UTC');
            $phases['handoff'] = $this->runtime->handoff($id);
            $this->recorder->progress($record, $phases);
            $step = 'verify';
            $verified = $this->verifier->verify($sha);
            $phases['verify'] = ['outcome' => 'passed', 'status' => $verified['status'], 'version' => $verified['version']];
            $this->recorder->progress($record, $phases);
            $step = 'scheduler';
            // The scheduler may restart on the new release before a later part of this phase fails.
            $scheduleStarted = true;
            $scheduled = $this->runtime->schedule($id);
            $phases['scheduler'] = $scheduled;
            $this->recorder->progress($record, $phases);
            $step = 'web';
            $phases['web'] = $this->publishWeb($id, $sha, $trigger);
            $this->recorder->progress($record, $phases);
            $step = 'smoke';
            $phases['smoke'] = $this->smoke->run($id, $sha, $handoffAt, $phases['web']['outcome'] === 'kept' ? ['web'] : []);
            // Smoke checks that the release schedules tasks:tick. Its first tick comes at the next full minute, so the
            // release runner confirms it later and never switches back for it.
            if ($this->ticks instanceof GatewayReleaseTickConfirmation) {
                $phases['tick'] = $this->ticks->start($sha, $handoffAt);
            }
        } catch (Throwable $exception) {
            $this->fail(
                exception: GatewayReleaseException::fromThrowable($exception, $step, $sha),
                id: $id,
                sha: $sha,
                trigger: $trigger,
                migrationsRan: $migrationsRan,
                snapshotPath: $snapshotPath,
                phases: $phases,
                startedAt: $startedAt,
                previous: $previous,
                scheduled: $scheduled,
                scheduleStarted: $scheduleStarted,
                record: $record,
            );
        }

        $release = $this->result(
            id: $id,
            sha: $sha,
            outcome: 'verified',
            trigger: $trigger,
            migrationsRan: $migrationsRan,
            previousId: $previous,
            snapshotPath: $snapshotPath,
            cleanupPaused: ($scheduled['cleanup_paused'] ?? false) === true,
            startedAt: $startedAt,
            phases: $phases,
        );
        $this->record($release, $record);
        $this->prune($id, $previous);
        $this->followFleet($id, $sha, $phases['verify']);

        return $release;
    }

    /**
     * @param  array<string, mixed>  $phases
     * @param  array<string, mixed>|null  $scheduled  the schedule phase's result, when the scheduler already moved
     */
    private function fail(
        GatewayReleaseException $exception,
        string $id,
        string $sha,
        string $trigger,
        bool $migrationsRan,
        ?string $snapshotPath,
        array $phases,
        int $startedAt,
        ?string $previous,
        ?array $scheduled,
        bool $scheduleStarted,
        ?GatewayRelease $record,
    ): never {
        $outcome = 'failed';
        $cleanupPaused = ($scheduled['cleanup_paused'] ?? false) === true;
        $phases[$exception->step] = [...$exception->phase, 'outcome' => 'failed', 'error_code' => $exception->errorCode];
        $switched = $previous !== $id && $this->layout->currentReleaseId() === $id;
        $unknown = $switched && $previous !== null && ! $migrationsRan ? $this->schemaRefusal($previous) : null;
        $this->recorder->progress($record, $phases);

        if ($migrationsRan || $unknown instanceof GatewayReleaseException) {
            // Never switch back onto code that has not run on the applied schema.
            $outcome = 'paused';
            $phases['pause'] = array_filter([
                'outcome' => 'paused',
                'snapshot' => $snapshotPath,
                'current' => $this->layout->currentReleaseId(),
                'reason' => $unknown?->getMessage(),
            ], static fn (mixed $value): bool => $value !== null);
        } elseif ($switched && $previous !== null) {
            try {
                $this->switcher->switchTo($previous);
                $this->web->restore($previous);
                $back = ['outcome' => 'switched_back', 'to' => $previous, 'handoff' => $this->runtime->handoff($previous)];

                if ($scheduleStarted) {
                    $back['scheduler'] = $this->runtime->schedule($previous);
                    $cleanupPaused = ($back['scheduler']['cleanup_paused'] ?? false) === true;
                }

                $phases['switch_back'] = $back;
                $outcome = 'switched_back';
            } catch (Throwable $thrown) {
                $back = GatewayReleaseException::fromThrowable($thrown, 'switch_back', $sha);
                $phases['switch_back'] = ['outcome' => 'failed', 'error_code' => $back->errorCode, 'current' => $this->layout->currentReleaseId()];
                $exception = new GatewayReleaseException(
                    step: 'switch',
                    errorCode: 'gateway.release_switch_back_failed',
                    message: $exception->getMessage().' Switch-back then failed: '.$back->getMessage(),
                    status: 500,
                    previous: $back,
                    sha: $sha,
                );
            }
        }

        $this->record($this->result(
            id: $id,
            sha: $sha,
            outcome: $outcome,
            trigger: $trigger,
            migrationsRan: $migrationsRan,
            previousId: $previous,
            snapshotPath: $snapshotPath,
            cleanupPaused: $cleanupPaused,
            startedAt: $startedAt,
            phases: $phases,
            errorCode: $exception->errorCode,
            message: $exception->getMessage(),
            retryable: $outcome !== 'paused' && $this->retry->retryable($exception->errorCode, $sha, $record?->id),
        ), $record);

        throw $exception;
    }

    /** Why the previous release must not serve the applied schema, or null when it may. */
    private function schemaRefusal(string $previous): ?GatewayReleaseException
    {
        try {
            $this->guard->assertSchema($previous, false);
        } catch (Throwable $exception) {
            // Fail closed: a schema that cannot be read may hold migrations the previous code does not know.
            return GatewayReleaseException::fromThrowable($exception, 'switch_back');
        }

        return null;
    }

    /**
     * Writes the record without letting a failed write change the outcome: a verified release is
     * not switched back because its row could not be stored.
     */
    private function record(DeployedGatewayRelease $release, ?GatewayRelease $record = null): void
    {
        try {
            $this->recorder->write($release, $record);
        } catch (Throwable $exception) {
            Log::error('The Gateway release record could not be written.', [
                'release' => $release->toArray(),
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $phases
     */
    private function result(
        string $id,
        string $sha,
        string $outcome,
        string $trigger,
        bool $migrationsRan,
        ?string $previousId,
        ?string $snapshotPath,
        bool $cleanupPaused,
        int $startedAt,
        array $phases,
        ?string $errorCode = null,
        ?string $message = null,
        bool $retryable = false,
    ): DeployedGatewayRelease {
        return new DeployedGatewayRelease(
            id: $id,
            sha: $sha,
            outcome: $outcome,
            trigger: $trigger,
            migrationsRan: $migrationsRan,
            previousId: $previousId,
            snapshotPath: $snapshotPath,
            cleanupPaused: $cleanupPaused,
            retryable: $retryable,
            durationMs: intdiv(hrtime(true) - $startedAt, 1_000_000),
            phases: $phases,
            errorCode: $errorCode,
            message: $message,
        );
    }

    /**
     * The fleet follows a verified release (ADR 0202). The rollout runs in its own unit from the new release, and
     * starting it never fails or rolls back this release. It starts only when the release has a desired fleet
     * state: the Gateway serves the release's exact commit, not `dev`, and the release ships the rollout command.
     * Adopt's phase 1 never comes here; it records its own outcome.
     *
     * @param  array<string, mixed>  $verify
     */
    private function followFleet(string $id, string $sha, array $verify): void
    {
        if (! $this->fleet instanceof FleetConvergeUnits) {
            return;
        }

        $version = is_string($verify['version'] ?? null) ? strtolower(trim($verify['version'])) : '';
        $exact = GatewayReleaseCommit::isSha($sha)
            && ($version === strtolower($sha) || $version === GatewayReleaseCommit::id($sha));

        if (! $exact || ! is_file($this->layout->releaseApplicationPath($id).'/'.self::FleetCommand)) {
            Log::info('The fleet rollout does not follow this release: it has no desired fleet state.', ['release' => $id, 'version' => $version]);

            return;
        }

        $this->fleet->start();
    }

    /**
     * Switches the web app to the release's build. A deploy fails without that build. A rollback is often an
     * emergency, so it installs a missing build from CI first, and when CI no longer has it, keeps the web app as it
     * is and continues with a warning instead of blocking the code rollback on assets.
     *
     * @return array{outcome: string, installed?: bool, warning?: string, error_code?: string}
     */
    private function publishWeb(string $id, string $sha, string $trigger): array
    {
        try {
            $this->web->publish($id);

            return ['outcome' => 'published'];
        } catch (GatewayReleaseException $exception) {
            if ($trigger !== 'rollback' || $exception->errorCode !== 'gateway.release_web_build_missing') {
                throw $exception;
            }
        }

        try {
            $this->web->install($id, $sha);
            $this->web->publish($id);

            return ['outcome' => 'published', 'installed' => true];
        } catch (GatewayReleaseException $exception) {
            return [
                'outcome' => 'kept',
                'warning' => 'The web build of the rollback target is not available, so the web app stays as it was: '.$exception->getMessage(),
                'error_code' => $exception->errorCode,
            ];
        }
    }

    /**
     * Keeps the current and the previous release, whatever their age, and fills the rest of the kept count with the
     * newest other releases. It also removes the release directories that a stopped prepare left without `REVISION`.
     */
    private function prune(string $current, ?string $previous): void
    {
        $ids = $this->layout->retainedReleaseIds();
        $protected = array_values(array_filter([$this->layout->currentReleaseId(), $current, $previous]));
        $keep = [
            ...$protected,
            ...array_slice(array_values(array_unique([...array_intersect($protected, $ids), ...$ids])), 0, max(2, $this->keptReleases)),
        ];

        foreach ($ids as $id) {
            if (in_array($id, $keep, true)) {
                continue;
            }

            try {
                $this->builder->remove($id);
            } catch (Throwable) {
                // Pruning is not the release. A release that cannot be removed stays until the next one.
            }
        }

        $this->pruneIncomplete([$current, $previous, $this->layout->currentReleaseId()]);
        $retained = $this->layout->retainedReleaseIds();

        // A verified release is retained, so an empty list means the releases directory could not be read. Pruning
        // the web builds against it would remove every build but the current one.
        if ($retained === [] || ! is_readable($this->layout->releasesPath())) {
            Log::warning('Gateway release skipped pruning web builds: the releases directory cannot be read.', ['releases' => $this->layout->releasesPath()]);

            return;
        }

        try {
            $this->web->prune($retained);
        } catch (Throwable) {
            // A web build that cannot be removed now is removed by a later release.
        }
    }

    /**
     * Removes each release directory without `REVISION` that is older than IncompleteReleaseSeconds, and its web
     * build. The current and the previous release stay, also when they are incomplete, because they need a repair,
     * not a removal.
     *
     * @param  list<string|null>  $kept
     */
    private function pruneIncomplete(array $kept): void
    {
        foreach ($this->layout->incompleteReleaseIds() as $id) {
            $modified = @filemtime($this->layout->releasePath($id));

            if (in_array($id, $kept, true) || $modified === false || $modified > time() - self::IncompleteReleaseSeconds) {
                continue;
            }

            try {
                $this->builder->remove($id);
            } catch (Throwable $exception) {
                // Pruning is not the release. A directory that cannot be removed stays until the next one.
                Log::warning('Gateway release could not prune an incomplete release directory.', [
                    'release' => $id,
                    'error_code' => $exception instanceof GatewayReleaseException ? $exception->errorCode : null,
                    'message' => $exception->getMessage(),
                ]);
            }
        }
    }
}
