<?php

declare(strict_types=1);

namespace App\Actions\GatewayReleases;

use App\Domain\GatewayReleases\DeployedGatewayRelease;
use App\Domain\GatewayReleases\GatewayReleaseCommit;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Infrastructure\GatewayReleases\GatewayReleaseGuard;
use App\Infrastructure\GatewayReleases\GatewayReleaseLock;
use App\Infrastructure\GatewayReleases\GatewayReleasePromoter;
use App\Infrastructure\GatewayReleases\GatewayReleaseRecorder;
use App\Models\GatewayRelease;
use Throwable;

/**
 * Switches back to a retained release. It refuses when the database has applied a migration the
 * target does not ship, unless the caller passes force. Force does not migrate backwards; it names the
 * newest snapshot and leaves the schema where it is. A refusal changes nothing; its record ends
 * failed and retryable. An attempt that switches is recorded like a deploy.
 */
final readonly class RollbackGatewayReleaseAction
{
    public function __construct(
        private GatewayReleaseLock $lock,
        private GatewayReleaseLayout $layout,
        private GatewayReleasePromoter $promoter,
        private GatewayReleaseRecorder $recorder,
        private GatewayReleaseGuard $guard,
    ) {}

    /** @param GatewayRelease|null $record a queued record to claim, or null to start a new one */
    public function execute(string $release, bool $force = false, ?GatewayRelease $record = null): DeployedGatewayRelease
    {
        return $this->lock->run(function () use ($release, $force, $record): DeployedGatewayRelease {
            $startedAt = hrtime(true);
            $record = $this->recorder->begin('rollback', $release, $record, $force);

            try {
                [$id, $sha, $snapshot] = $this->target($release, $force);
            } catch (Throwable $thrown) {
                $exception = GatewayReleaseException::fromThrowable($thrown, 'rollback');
                $this->recorder->fail($record, $exception, retryable: true, durationMs: intdiv(hrtime(true) - $startedAt, 1_000_000));

                throw $exception;
            }

            try {
                return $this->promoter->promote(
                    id: $id,
                    sha: $sha,
                    trigger: 'rollback',
                    migrationsRan: false,
                    snapshotPath: $snapshot,
                    phases: ['rollback' => ['outcome' => 'started', 'force' => $force, 'snapshot' => $snapshot]],
                    startedAt: $startedAt,
                    record: $record,
                );
            } catch (Throwable $exception) {
                $this->recorder->fail($record, $exception, retryable: true, durationMs: intdiv(hrtime(true) - $startedAt, 1_000_000));

                throw $exception;
            }
        });
    }

    /**
     * The commit of a retained release that may become current. A caller checks this before it
     * queues a rollback, and the rollback checks it again under the lock.
     *
     * @throws GatewayReleaseException
     */
    public function check(string $release, bool $force): string
    {
        return $this->target($release, $force)[1];
    }

    /**
     * The retained release to return to, its commit, and the newest pre-migration snapshot.
     *
     * @return array{string, string, string|null}
     */
    private function target(string $release, bool $force): array
    {
        $id = GatewayReleaseCommit::assertId($release);
        $sha = $this->layout->preparedCommit($id);

        if ($sha === null) {
            throw new GatewayReleaseException(
                step: 'rollback',
                errorCode: 'gateway.release_not_prepared',
                message: "Release [{$id}] is not a retained release.",
                status: 422,
            );
        }

        $current = $this->layout->currentReleaseId();

        if ($current === null) {
            throw new GatewayReleaseException(
                step: 'rollback',
                errorCode: 'gateway.release_not_adopted',
                message: 'The Gateway is not running from a release. Run gateway:release:adopt first.',
            );
        }

        // The applied schema, not the current release's files: the database may be ahead of both.
        $this->guard->assertSchema($id, $force);

        $snapshot = GatewayRelease::query()
            ->where('migrations_ran', true)
            ->whereNotNull('snapshot_path')
            ->latest('id')
            ->value('snapshot_path');

        return [$id, $sha, is_string($snapshot) ? $snapshot : null];
    }
}
