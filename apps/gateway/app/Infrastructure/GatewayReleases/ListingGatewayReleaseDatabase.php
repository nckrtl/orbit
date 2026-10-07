<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Domain\GatewayReleases\GatewayReleaseDatabase;
use App\Domain\GatewayReleases\GatewayReleaseException;

/**
 * Reads the migration files a release ships and treats every release as having nothing pending.
 * The migration slice replaces pending, snapshot, and migrate. Rollback already uses the file list
 * to refuse crossing a migration that the current release applied.
 */
final class ListingGatewayReleaseDatabase implements GatewayReleaseDatabase
{
    public function pending(string $releasePath): array
    {
        return [];
    }

    public function snapshot(string $id): string
    {
        throw new GatewayReleaseException(
            step: 'snapshot',
            errorCode: 'gateway.release_snapshot_unavailable',
            message: 'This Gateway release build does not snapshot the database yet.',
            status: 500,
        );
    }

    public function migrate(string $releasePath): void {}

    public function migrations(string $releasePath): array
    {
        $directory = $releasePath.'/apps/gateway/database/migrations';
        $entries = @scandir($directory);

        if ($entries === false) {
            return [];
        }

        $files = [];

        foreach ($entries as $entry) {
            if (preg_match('/\A[0-9A-Za-z_]+_[\w]+\.php\z/D', $entry) === 1) {
                $files[] = $entry;
            }
        }

        sort($files);

        return $files;
    }
}
