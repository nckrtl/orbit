<?php

declare(strict_types=1);

namespace App\Actions\GatewayReleases;

use App\Domain\GatewayReleases\DeployedGatewayRelease;
use App\Domain\GatewayReleases\GatewayReleaseDatabase;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Infrastructure\GatewayReleases\GatewayReleaseBuilder;
use App\Infrastructure\GatewayReleases\GatewayReleaseLock;
use App\Infrastructure\GatewayReleases\GatewayReleasePromoter;
use App\Infrastructure\GatewayReleases\GatewayReleaseRecorder;

/**
 * Prepares a commit, migrates when that release has pending migrations, then promotes it.
 * Prepare failures mark the commit failed unless the failure is about the machine (disk, lock,
 * layout) rather than the commit.
 */
final readonly class DeployGatewayReleaseAction
{
    /** @var list<string> */
    private const array RETRYABLE = [
        'gateway.release_disk_low',
        'gateway.release_in_progress',
        'gateway.release_layout_missing',
        'gateway.release_layout_invalid',
        'gateway.release_lock_unavailable',
        'gateway.release_commit_invalid',
        'gateway.release_commit_unknown',
        'gateway.release_snapshot_failed',
        'gateway.release_snapshot_unavailable',
    ];

    public function __construct(
        private GatewayReleaseLock $lock,
        private GatewayReleaseBuilder $builder,
        private GatewayReleaseDatabase $database,
        private GatewayReleasePromoter $promoter,
        private GatewayReleaseRecorder $recorder,
    ) {}

    public function execute(string $commit): DeployedGatewayRelease
    {
        return $this->lock->run(function () use ($commit): DeployedGatewayRelease {
            $startedAt = hrtime(true);

            try {
                $prepared = $this->builder->prepare($commit);
            } catch (GatewayReleaseException $exception) {
                $this->recordPrepareFailure($exception, $startedAt);

                throw $exception;
            }

            $phases = ['prepare' => ['outcome' => $prepared->reused ? 'reused' : 'prepared', 'duration_ms' => $prepared->durationMs]];
            $pending = $this->database->pending($prepared->path);
            $migrationsRan = false;
            $snapshot = null;

            if ($pending !== []) {
                try {
                    $snapshot = $this->database->snapshot($prepared->id);
                    $phases['snapshot'] = ['outcome' => 'snapshotted', 'path' => $snapshot, 'pending' => $pending];
                    $this->database->migrate($prepared->path);
                    $migrationsRan = true;
                    $phases['migrate'] = ['outcome' => 'migrated', 'pending' => $pending];
                } catch (GatewayReleaseException $exception) {
                    $outcome = $exception->step === 'migrate' ? 'paused' : 'failed';
                    $this->recorder->write(new DeployedGatewayRelease(
                        id: $prepared->id,
                        sha: $prepared->sha,
                        outcome: $outcome,
                        trigger: 'deploy',
                        migrationsRan: $exception->step === 'migrate',
                        previousId: null,
                        snapshotPath: $snapshot,
                        cleanupPaused: false,
                        retryable: in_array($exception->errorCode, self::RETRYABLE, true),
                        durationMs: intdiv(hrtime(true) - $startedAt, 1_000_000),
                        phases: [...$phases, $exception->step => ['outcome' => 'failed', 'error_code' => $exception->errorCode]],
                        errorCode: $exception->errorCode,
                        message: $exception->getMessage(),
                    ));

                    throw $exception;
                }
            } else {
                $phases['snapshot'] = ['outcome' => 'skipped'];
                $phases['migrate'] = ['outcome' => 'skipped'];
            }

            return $this->promoter->promote(
                id: $prepared->id,
                sha: $prepared->sha,
                trigger: 'deploy',
                migrationsRan: $migrationsRan,
                snapshotPath: $snapshot,
                phases: $phases,
                startedAt: $startedAt,
            );
        });
    }

    private function recordPrepareFailure(GatewayReleaseException $exception, int $startedAt): void
    {
        if ($exception->sha === null || strlen($exception->sha) !== 40) {
            return;
        }

        if (in_array($exception->errorCode, self::RETRYABLE, true)) {
            return;
        }

        $this->recorder->write(new DeployedGatewayRelease(
            id: substr($exception->sha, 0, 12),
            sha: $exception->sha,
            outcome: 'failed',
            trigger: 'deploy',
            migrationsRan: false,
            previousId: null,
            snapshotPath: null,
            cleanupPaused: false,
            retryable: false,
            durationMs: intdiv(hrtime(true) - $startedAt, 1_000_000),
            phases: ['prepare' => ['outcome' => 'failed', 'error_code' => $exception->errorCode]],
            errorCode: $exception->errorCode,
            message: $exception->getMessage(),
        ));
    }
}
