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
use Throwable;

/**
 * Prepares a commit, migrates when that release has pending migrations, then promotes it.
 * Every attempt that names a commit is recorded. A failure about the machine (disk, lock,
 * layout, or the database snapshot) is recorded as retryable, so the commit is not marked
 * failed. A failed migration pauses.
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
        'gateway.release_fetch_failed',
        'gateway.release_migrations_unreadable',
        'gateway.release_snapshot_failed',
        'gateway.release_snapshot_unavailable',
        'gateway.release_unexpected_failure',
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
            $step = 'prepare';
            $prepared = null;
            $phases = [];
            $snapshot = null;
            $migrationsRan = false;

            try {
                $prepared = $this->builder->prepare($commit);
                $phases['prepare'] = ['outcome' => $prepared->reused ? 'reused' : 'prepared', 'duration_ms' => $prepared->durationMs];
                $step = 'snapshot';
                $pending = $this->database->pending($prepared->path);

                if ($pending === []) {
                    $phases['snapshot'] = ['outcome' => 'skipped'];
                    $phases['migrate'] = ['outcome' => 'skipped'];
                } else {
                    $snapshot = $this->database->snapshot($prepared->id);
                    $phases['snapshot'] = ['outcome' => 'snapshotted', 'path' => $snapshot, 'pending' => $pending];
                    $step = 'migrate';
                    $this->database->migrate($prepared->path);
                    $migrationsRan = true;
                    $phases['migrate'] = ['outcome' => 'migrated', 'pending' => $pending];
                }
            } catch (Throwable $thrown) {
                $exception = GatewayReleaseException::fromThrowable($thrown, $step, $prepared?->sha);
                $this->recordFailure($exception, $step, $phases, $snapshot, $startedAt);

                throw $exception;
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

    /**
     * A failed migration may have applied part of its changes, so it pauses like a failure after
     * migrations. Without a full commit there is no release to record, only the Activity entry.
     *
     * @param  array<string, mixed>  $phases
     */
    private function recordFailure(GatewayReleaseException $exception, string $step, array $phases, ?string $snapshot, int $startedAt): void
    {
        $durationMs = intdiv(hrtime(true) - $startedAt, 1_000_000);

        try {
            if ($exception->sha === null || strlen($exception->sha) !== 40) {
                $this->recorder->refused('deploy', $exception, $durationMs);

                return;
            }

            $migrating = $step === 'migrate';
            $this->recorder->write(new DeployedGatewayRelease(
                id: substr($exception->sha, 0, 12),
                sha: $exception->sha,
                outcome: $migrating ? 'paused' : 'failed',
                trigger: 'deploy',
                migrationsRan: $migrating,
                previousId: null,
                snapshotPath: $snapshot,
                cleanupPaused: false,
                retryable: ! $migrating && in_array($exception->errorCode, self::RETRYABLE, true),
                durationMs: $durationMs,
                phases: [...$phases, $step => ['outcome' => 'failed', 'error_code' => $exception->errorCode]],
                errorCode: $exception->errorCode,
                message: $exception->getMessage(),
            ));
        } catch (Throwable) {
            // The failure itself is what the caller needs; a record that cannot be written must not replace it.
        }
    }
}
