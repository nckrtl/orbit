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
use Throwable;

/**
 * Prepares a commit, checks that it moves forward and knows the applied schema, caches its
 * configuration, migrates when it has pending migrations, then promotes it. Every attempt that
 * names a commit is recorded; {@see GatewayReleaseRetry} decides whether it may be tried again.
 * A refusal by the guard writes only an Activity entry. A failed migration pauses.
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
    ) {}

    /** @param bool $force switch even when the commit does not descend from the current one or does not know an applied migration */
    public function execute(string $commit, bool $force = false): DeployedGatewayRelease
    {
        return $this->lock->run(function () use ($commit, $force): DeployedGatewayRelease {
            $startedAt = hrtime(true);
            $step = 'prepare';
            $prepared = null;
            $phases = [];
            $snapshot = null;
            $migrationsRan = false;

            try {
                $prepared = $this->builder->prepare($commit);
                $phases['prepare'] = ['outcome' => $prepared->reused ? 'reused' : 'prepared', 'duration_ms' => $prepared->durationMs];
                $step = 'guard';
                $this->guard->assertForward($prepared->sha, $force);
                $this->guard->assertSchema($prepared->id, $force);
                $phases['guard'] = ['outcome' => 'passed', 'force' => $force];
                // Before any migration: a configuration that cannot be cached must stop the release while nothing changed.
                $step = 'configuration';
                $this->builder->refreshConfiguration($prepared->id);
                $phases['configuration'] = ['outcome' => 'cached'];
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
            if ($exception->sha === null || strlen($exception->sha) !== 40 || $step === 'guard') {
                // A refusal changes nothing and does not mark the commit failed.
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
                retryable: ! $migrating && $this->retry->retryable($exception->errorCode, $exception->sha),
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
