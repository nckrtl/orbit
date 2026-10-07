<?php

declare(strict_types=1);

namespace App\Actions\GatewayReleases;

use App\Domain\GatewayReleases\DeployedGatewayRelease;
use App\Domain\GatewayReleases\GatewayReleaseCommit;
use App\Domain\GatewayReleases\GatewayReleaseDatabase;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Infrastructure\GatewayReleases\GatewayReleaseLock;
use App\Infrastructure\GatewayReleases\GatewayReleasePromoter;
use App\Models\GatewayRelease;

/**
 * Switches back to a retained release. It refuses when the current release ships a migration the
 * target does not, unless the caller passes force. Force does not migrate backwards; it names the
 * newest snapshot and leaves the schema where it is.
 */
final readonly class RollbackGatewayReleaseAction
{
    public function __construct(
        private GatewayReleaseLock $lock,
        private GatewayReleaseLayout $layout,
        private GatewayReleaseDatabase $database,
        private GatewayReleasePromoter $promoter,
    ) {}

    public function execute(string $release, bool $force = false): DeployedGatewayRelease
    {
        return $this->lock->run(function () use ($release, $force): DeployedGatewayRelease {
            $startedAt = hrtime(true);
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

            if ($current !== $id) {
                $this->assertMigrations($current, $id, $force);
            }

            $snapshot = GatewayRelease::query()
                ->where('migrations_ran', true)
                ->whereNotNull('snapshot_path')
                ->latest('id')
                ->value('snapshot_path');

            return $this->promoter->promote(
                id: $id,
                sha: $sha,
                trigger: 'rollback',
                migrationsRan: false,
                snapshotPath: is_string($snapshot) ? $snapshot : null,
                phases: ['rollback' => ['outcome' => 'started', 'force' => $force, 'snapshot' => $snapshot]],
                startedAt: $startedAt,
            );
        });
    }

    private function assertMigrations(string $current, string $target, bool $force): void
    {
        $currentFiles = $this->database->migrations($this->layout->releasePath($current));
        $targetFiles = $this->database->migrations($this->layout->releasePath($target));
        $crossed = array_values(array_diff($currentFiles, $targetFiles));

        if ($crossed === [] || $force) {
            return;
        }

        $snapshot = GatewayRelease::query()
            ->where('migrations_ran', true)
            ->whereNotNull('snapshot_path')
            ->latest('id')
            ->value('snapshot_path');
        $where = is_string($snapshot) ? " Restore [{$snapshot}] or pass --force to switch the code and leave the schema." : ' Pass --force to switch the code and leave the schema.';

        throw new GatewayReleaseException(
            step: 'rollback',
            errorCode: 'gateway.release_migration_crossed',
            message: 'Release ['.$target.'] does not contain '.$crossed[0].'.'.$where,
            status: 409,
        );
    }
}
