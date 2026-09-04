<?php

declare(strict_types=1);

namespace App\Data\Routes;

use App\Models\Route;
use App\Models\RouteTarget;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/** @mago-expect lint:excessive-parameter-list The value mirrors the complete bounded Route response. */
#[MapOutputName(SnakeCaseMapper::class)]
final class RouteData extends Data
{
    public function __construct(
        public int $id,
        public int $appId,
        public ?int $nodeId,
        public ?int $clusterId,
        public ?int $generationBasisNodeId,
        public string $hostname,
        public string $provenance,
        public string $publication,
        public string $status,
        public ?string $failedStep,
        public ?string $errorCode,
        public ?RouteTargetData $target,
    ) {}

    public static function fromModel(Route $route): self
    {
        $route->loadMissing('targets');
        $target = $route->targets->first();

        return new self(
            id: $route->id,
            appId: $route->app_id,
            nodeId: $route->node_id,
            clusterId: $route->cluster_id,
            generationBasisNodeId: $route->generation_basis_node_id,
            hostname: $route->hostname,
            provenance: $route->provenance->value,
            publication: $route->publication->value,
            status: $route->status->value,
            failedStep: $route->failed_step,
            errorCode: $route->error_code,
            target: $target instanceof RouteTarget ? RouteTargetData::fromModel($target) : null,
        );
    }
}
