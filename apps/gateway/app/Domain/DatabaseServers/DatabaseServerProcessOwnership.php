<?php

declare(strict_types=1);

namespace App\Domain\DatabaseServers;

use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseServer;
use App\Models\Process;
use SensitiveParameter;

/**
 * A Database server owns its Process. `database:server:destroy` removes it, not `process:destroy`. A Valkey server's
 * Process keeps the server's command, so `process:update` cannot drop `--aclfile` and open the server (ADR 0221).
 */
final readonly class DatabaseServerProcessOwnership
{
    public function assertRemovable(#[SensitiveParameter] Process $process): void
    {
        $server = DatabaseServer::query()->where('process_id', $process->id)->first();

        if ($server instanceof DatabaseServer) {
            throw new ResourceOperationException(
                errorCode: 'process.required_by_database_server',
                message: "Process [{$process->name}] runs Database server [{$server->slug}]. Use database:server:destroy.",
                status: 409,
            );
        }
    }

    public function assertUpdatable(#[SensitiveParameter] Process $process): void
    {
        $server = DatabaseServer::query()
            ->where('process_id', $process->id)
            ->where('driver', DatabaseDriver::Redis)
            ->first();

        if ($server instanceof DatabaseServer) {
            throw new ResourceOperationException(
                errorCode: 'process.required_by_database_server',
                message: "Process [{$process->name}] runs Valkey Database server [{$server->slug}], so its command keeps the server's ACL file.",
                status: 409,
            );
        }
    }
}
