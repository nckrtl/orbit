<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Domain\GatewayReleases\GatewayReleaseDatabase;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Models\GatewayRelease;

/**
 * The checks that keep code from running on a schema it does not know, for deploy and rollback alike:
 *
 * - every migration the database has applied must be a file in the target release;
 * - a deploy must move forward: the current release's commit is an ancestor of the target.
 *
 * `--force` skips both. It never migrates backwards.
 */
final readonly class GatewayReleaseGuard
{
    public function __construct(
        private GatewayReleaseLayout $layout,
        private GatewayReleaseDatabase $database,
        private ProcessRunner $processes,
    ) {}

    /** @throws GatewayReleaseException */
    public function assertSchema(string $id, bool $force): void
    {
        if ($force) {
            return;
        }

        // An unreadable migrations table throws gateway.release_migrations_unreadable: the check fails closed.
        $files = array_fill_keys(array_map(
            static fn (string $file): string => substr($file, 0, -4),
            $this->database->migrations($this->layout->releasePath($id)),
        ), true);
        $unknown = array_values(array_filter($this->database->applied(), static fn (string $name): bool => ! isset($files[$name])));

        if ($unknown === []) {
            return;
        }

        $snapshot = GatewayRelease::query()
            ->where('migrations_ran', true)
            ->whereNotNull('snapshot_path')
            ->latest('id')
            ->value('snapshot_path');
        $where = is_string($snapshot) ? " Restore [{$snapshot}] or pass --force to switch the code and leave the schema." : ' Pass --force to switch the code and leave the schema.';

        throw new GatewayReleaseException(
            step: 'guard',
            errorCode: 'gateway.release_migration_crossed',
            message: sprintf('Release [%s] does not know %d migration(s) the database has applied, such as [%s].%s', $id, count($unknown), $unknown[0], $where),
            status: 409,
        );
    }

    /** @throws GatewayReleaseException */
    public function assertForward(string $sha, bool $force): void
    {
        $current = $this->layout->currentReleaseId();
        $currentSha = $current === null ? null : $this->layout->preparedCommit($current);

        if ($force || $currentSha === null || $currentSha === $sha) {
            return;
        }

        $result = $this->processes->run(new ProcessInvocation(
            ['git', '-C', $this->layout->repositoryPath(), 'merge-base', '--is-ancestor', $currentSha, $sha],
            timeout: 30.0,
        ));

        if ($result->succeeded()) {
            return;
        }

        throw new GatewayReleaseException(
            step: 'guard',
            errorCode: 'gateway.release_downgrade',
            message: "Commit [{$sha}] does not descend from the current release [{$currentSha}]. Use gateway:release:rollback, or pass --force.",
            status: 409,
            sha: $sha,
        );
    }
}
