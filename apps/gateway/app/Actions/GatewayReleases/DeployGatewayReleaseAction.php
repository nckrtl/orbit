<?php

declare(strict_types=1);

namespace App\Actions\GatewayReleases;

use App\Domain\GatewayReleases\DeployedGatewayRelease;
use App\Domain\GatewayReleases\GatewayReleaseDatabase;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Infrastructure\GatewayReleases\GatewayReleaseBuilder;
use App\Infrastructure\GatewayReleases\GatewayReleaseGuard;
use App\Infrastructure\GatewayReleases\GatewayReleaseLock;
use App\Infrastructure\GatewayReleases\GatewayReleasePromoter;
use App\Infrastructure\GatewayReleases\GatewayReleaseRecorder;
use App\Infrastructure\GatewayReleases\GatewayReleaseRetry;
use App\Infrastructure\GatewayReleases\GatewayReleaseSupersession;
use App\Models\GatewayRelease;
use Throwable;

/**
 * Prepares a commit, checks that it moves forward and knows the applied schema, caches its
 * configuration, migrates when it has pending migrations, then promotes it. Every attempt that
 * names a commit is recorded; {@see GatewayReleaseRetry} decides whether it may be tried again.
 * A refusal by the guard ends the record without marking the commit failed. A failed migration pauses.
 *
 * The record exists from the start of the attempt and stores each step as it ends, so a caller
 * can follow it ([Release records](/reference/gateway-recovery#release-records)). A verified manual
 * deploy also records whether a newer green commit existed, which decides whether automatic
 * releases pause ({@see GatewayReleaseSupersession}).
 */
final readonly class DeployGatewayReleaseAction
{
    public function __construct(
        private GatewayReleaseLock $lock,
        private GatewayReleaseBuilder $builder,
        private GatewayReleaseDatabase $database,
        private GatewayReleasePromoter $promoter,
        private GatewayReleaseRecorder $recorder,
        private GatewayReleaseGuard $guard,
        private GatewayReleaseRetry $retry,
        private GatewayReleaseSupersession $supersession,
    ) {}

    /**
     * @param  bool  $force  switch even when the commit does not descend from the current one or does not know an applied migration
     * @param  string  $trigger  `deploy` for a manual release, `auto` for the automatic runner
     * @param  GatewayRelease|null  $record  a queued record to claim, or null to start a new one
     */
    public function execute(string $commit, bool $force = false, string $trigger = 'deploy', ?GatewayRelease $record = null): DeployedGatewayRelease
    {
        return $this->lock->run(fn (): DeployedGatewayRelease => $this->deploy($commit, $force, $trigger, $record));
    }

    /**
     * The deploy itself, for a caller that already holds the release lock, such as adoption's second phase.
     *
     * @param  string  $trigger  what the release record names as its trigger
     * @param  GatewayRelease|null  $record  a queued record to claim, or null to start a new one
     */
    public function deploy(string $commit, bool $force, string $trigger, ?GatewayRelease $record = null): DeployedGatewayRelease
    {
        $startedAt = hrtime(true);
        $record = $this->recorder->begin($trigger, $commit, $record, $force);

        try {
            $deployed = $this->attempt($commit, $force, $trigger, $record, $startedAt);
        } catch (Throwable $exception) {
            $this->recorder->fail($record, $exception, retryable: true, durationMs: intdiv(hrtime(true) - $startedAt, 1_000_000));

            throw $exception;
        }

        if ($record instanceof GatewayRelease && $trigger === 'deploy' && $deployed->succeeded()) {
            // Whether this manual deploy pins an older commit on purpose decides if automation pauses.
            $record->refresh();
            $this->recorder->progress($record, [...$record->phases, 'newest_green' => $this->supersession->check($deployed->sha)]);
        }

        return $deployed;
    }

    private function attempt(string $commit, bool $force, string $trigger, ?GatewayRelease $record, int $startedAt): DeployedGatewayRelease
    {
        $step = 'prepare';
        $prepared = null;
        $phases = [];
        $snapshot = null;
        $migrationsRan = false;

        try {
            $prepared = $this->builder->prepare($commit);
            $phases['prepare'] = ['outcome' => $prepared->reused ? 'reused' : 'prepared', 'duration_ms' => $prepared->durationMs];
            $this->recorder->progress($record, $phases, $prepared->sha);
            $step = 'guard';
            $this->guard->assertForward($prepared->sha, $force);
            $this->guard->assertSchema($prepared->id, $force);
            $phases['guard'] = ['outcome' => 'passed', 'force' => $force];
            // Before any migration: a configuration that cannot be cached must stop the release while nothing changed.
            $step = 'configuration';
            $this->builder->refreshConfiguration($prepared->id);
            $phases['configuration'] = ['outcome' => 'cached'];
            $this->recorder->progress($record, $phases);
            $step = 'snapshot';
            $pending = $this->database->pending($prepared->path);

            if ($pending === []) {
                $phases['snapshot'] = ['outcome' => 'skipped'];
                $phases['migrate'] = ['outcome' => 'skipped'];
                $this->recorder->progress($record, $phases);
            } else {
                $taken = $this->database->snapshot($prepared->id);
                // A retry of a paused commit keeps naming the copy from before its first migration attempt.
                $snapshot = $this->recorder->pausedSnapshot($prepared->sha) ?? $taken;
                $phases['snapshot'] = ['outcome' => 'snapshotted', 'path' => $taken, 'clean' => $snapshot, 'pending' => $pending];
                $this->recorder->progress($record, $phases);
                $step = 'migrate';
                $this->database->migrate($prepared->path);
                $migrationsRan = true;
                $phases['migrate'] = ['outcome' => 'migrated', 'pending' => $pending];
                $this->recorder->progress($record, $phases);
            }
        } catch (Throwable $thrown) {
            $exception = GatewayReleaseException::fromThrowable($thrown, $step, $prepared?->sha);
            $this->recordFailure($exception, $step, $phases, $snapshot, $startedAt, $trigger, $record);

            throw $exception;
        }

        return $this->promoter->promote(
            id: $prepared->id,
            sha: $prepared->sha,
            trigger: $trigger,
            migrationsRan: $migrationsRan,
            snapshotPath: $snapshot,
            phases: $phases,
            startedAt: $startedAt,
            record: $record,
        );
    }

    /**
     * A failed migration may have applied part of its changes, so it pauses like a failure after
     * migrations. Without a full commit, and for a refusal by the guard, the record ends failed
     * and retryable: nothing changed and the commit is not marked failed.
     *
     * @param  array<string, mixed>  $phases
     */
    private function recordFailure(GatewayReleaseException $exception, string $step, array $phases, ?string $snapshot, int $startedAt, string $trigger, ?GatewayRelease $record): void
    {
        $durationMs = intdiv(hrtime(true) - $startedAt, 1_000_000);

        try {
            if ($exception->sha === null || strlen($exception->sha) !== 40 || $step === 'guard') {
                $this->recorder->fail($record, $exception, retryable: true, durationMs: $durationMs, phase: $step);

                return;
            }

            $migrating = $step === 'migrate';
            $this->recorder->write(new DeployedGatewayRelease(
                id: substr($exception->sha, 0, 12),
                sha: $exception->sha,
                outcome: $migrating ? 'paused' : 'failed',
                trigger: $trigger,
                migrationsRan: $migrating,
                previousId: null,
                snapshotPath: $snapshot,
                cleanupPaused: false,
                retryable: ! $migrating && $this->retry->retryable($exception->errorCode, $exception->sha, $record?->id),
                durationMs: $durationMs,
                phases: [...$phases, $step => ['outcome' => 'failed', 'error_code' => $exception->errorCode]],
                errorCode: $exception->errorCode,
                message: $exception->getMessage(),
            ), $record);
        } catch (Throwable) {
            // The failure itself is what the caller needs; a record that cannot be written must not replace it.
        }
    }
}
