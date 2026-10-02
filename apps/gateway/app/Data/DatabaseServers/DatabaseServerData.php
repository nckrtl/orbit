<?php

declare(strict_types=1);

namespace App\Data\DatabaseServers;

use App\Models\DatabaseServer;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class DatabaseServerData extends Data
{
    public function __construct(
        public int $id,
        public string $slug,
        public int $nodeId,
        public ?int $processId,
        public string $tag,
        public int $port,
        public string $status,
        public int $databasesCount,
    ) {}

    public static function fromModel(DatabaseServer $server): self
    {
        return new self(
            id: $server->id,
            slug: $server->slug,
            nodeId: $server->node_id,
            processId: $server->process_id,
            tag: $server->tag,
            port: $server->port,
            status: $server->status->value,
            databasesCount: $server->databaseConnections()->count(),
        );
    }
}
