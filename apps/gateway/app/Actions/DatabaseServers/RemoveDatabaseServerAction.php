<?php

declare(strict_types=1);

namespace App\Actions\DatabaseServers;

use App\Actions\Processes\RemoveProcessAction;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseServer;
use App\Models\Process;

/**
 * Removes a Database server that no connection uses: first its Process, through the ordinary
 * Process removal, then the record. Docker keeps the named data volume.
 */
final readonly class RemoveDatabaseServerAction
{
    public function __construct(private RemoveProcessAction $processes) {}

    public function execute(DatabaseServer $server): void
    {
        if ($server->databaseConnections()->exists()) {
            throw new ResourceOperationException(
                errorCode: 'database.server_in_use',
                message: "Database server [{$server->slug}] still holds databases. Destroy their connections first.",
                status: 409,
            );
        }

        $process = $server->process;

        if ($process instanceof Process) {
            $this->processes->execute($process, removedByOwningRole: true);
        }

        $server->delete();
    }
}
