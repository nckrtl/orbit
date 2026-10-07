<?php

declare(strict_types=1);

namespace App\Domain\GatewayReleases;

/**
 * Pending migrations, the pre-migration snapshot, and the migrate that runs before a switch.
 * A release with no pending migrations does not snapshot and does not migrate.
 */
interface GatewayReleaseDatabase
{
    /**
     * Migration names the release would apply, in filename order.
     *
     * @return list<string>
     */
    public function pending(string $releasePath): array;

    /**
     * A consistent copy of the Gateway database at `$ORBIT_HOME/backups/pre-<id>.sqlite`.
     *
     * @return string absolute snapshot path
     */
    public function snapshot(string $id): string;

    public function migrate(string $releasePath): void;

    /** The bytes a snapshot of the database needs now, so prepare can keep room for it. */
    public function snapshotBytes(): int;

    /**
     * Migration filenames shipped in the release, so a rollback can see whether it would cross one.
     *
     * @return list<string>
     */
    public function migrations(string $releasePath): array;
}
