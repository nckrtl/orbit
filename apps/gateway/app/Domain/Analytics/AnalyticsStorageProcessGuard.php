<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use App\Domain\Nodes\RoleName;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Process;

/**
 * Proves that the two Processes named for the analytics role are database
 * servers the role can keep its data in: Node-targeted Docker Processes on an
 * active database Node, running the supported engines.
 */
final readonly class AnalyticsStorageProcessGuard
{
    /** PostgreSQL major 16, from the official image under any registry path. */
    public const string POSTGRES_IMAGE_PATTERN = '/(?:^|\/)postgres:16(?:[.-][A-Za-z0-9._-]*)?(?:@|$)/';

    public const string CLICKHOUSE_IMAGE_PATTERN = '/(?:^|\/)clickhouse\/clickhouse-server(?::|@|$)/';

    /** @return array{postgres: Process, clickhouse: Process} */
    public function assert(int $postgresProcessId, int $clickhouseProcessId): array
    {
        $postgres = $this->process($postgresProcessId, 'analytics.postgres_process_missing');
        $clickhouse = $this->process($clickhouseProcessId, 'analytics.clickhouse_process_missing');

        $this->assertOnDatabaseNode($postgres);
        $this->assertOnDatabaseNode($clickhouse);

        $this->assertImage(
            $postgres,
            self::POSTGRES_IMAGE_PATTERN,
            'analytics.postgres_unsupported',
            'a PostgreSQL 16 Process',
        );
        $this->assertImage(
            $clickhouse,
            self::CLICKHOUSE_IMAGE_PATTERN,
            'analytics.clickhouse_unsupported',
            'a ClickHouse Process',
        );

        return ['postgres' => $postgres, 'clickhouse' => $clickhouse];
    }

    private function process(int $id, string $missingCode): Process
    {
        $process = Process::query()->find($id);

        if (! $process instanceof Process) {
            throw new ResourceOperationException(
                errorCode: $missingCode,
                message: "Process [{$id}] does not exist.",
                status: 422,
            );
        }

        return $process;
    }

    private function assertOnDatabaseNode(Process $process): void
    {
        if ($process->owner_type !== Node::class) {
            throw $this->notNode($process);
        }

        if ($process->runtime !== ProcessRuntime::Docker) {
            throw new ResourceOperationException(
                errorCode: 'analytics.process_not_docker',
                message: "Process [{$process->name}] is not a Docker Process.",
                status: 422,
            );
        }

        $process->loadMissing('owner');
        $node = $process->owner;

        if (! $node instanceof Node) {
            throw $this->notNode($process);
        }

        $holdsDatabaseRole = $node->roles()
            ->where('role', RoleName::Database->value)
            ->where('status', LifecycleStatus::Active->value)
            ->exists();

        if ($node->status !== LifecycleStatus::Active || ! $holdsDatabaseRole) {
            throw new ResourceOperationException(
                errorCode: 'analytics.process_not_database_node',
                message: "Process [{$process->name}] is not on an active Node with the database role.",
                status: 422,
            );
        }
    }

    private function assertImage(Process $process, string $pattern, string $errorCode, string $expected): void
    {
        $image = $process->runtime_config['image'] ?? null;

        if (! is_string($image) || preg_match($pattern, $image) !== 1) {
            throw new ResourceOperationException(
                errorCode: $errorCode,
                message: "Process [{$process->name}] is not {$expected}.",
                status: 422,
            );
        }
    }

    private function notNode(Process $process): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'analytics.process_not_node',
            message: "Process [{$process->name}] is not a Node-targeted Process.",
            status: 422,
        );
    }
}
