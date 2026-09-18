<?php

declare(strict_types=1);

namespace App\Actions\DatabaseConnections;

use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Shared\ResourceOperationException;
use App\Models\DatabaseConnection;

final readonly class RemoveDatabaseConnectionAction
{
    public function __construct(private ?RecordEventBroadcaster $broadcaster = null) {}

    public function execute(DatabaseConnection $connection): void
    {
        if ($connection->targets()->exists()) {
            throw new ResourceOperationException(
                errorCode: 'database.connection_attached',
                message: "Detach Database connection [{$connection->slug}] from every AppInstance before removing it.",
                status: 409,
            );
        }

        $connection->delete();

        ($this->broadcaster ?? app(RecordEventBroadcaster::class))->broadcast(
            RecordEventType::DatabaseDeleted,
            $connection->id,
            ['id' => $connection->id, 'slug' => $connection->slug],
        );
    }
}
