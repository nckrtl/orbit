<?php

declare(strict_types=1);

namespace App\Data\Routes;

use App\Models\Route;
use App\Models\RouteRemovalResidue;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/**
 * The removed Route, as {@see RouteData} shows it, and the Nodes an offline removal left unchanged.
 * `retained_on_nodes` is empty unless the removal skipped a Node.
 */
#[MapOutputName(SnakeCaseMapper::class)]
final class RemovedRouteData extends Data
{
    public function __construct(
        public int $id,
        public string $kind,
        public ?int $projectId,
        public ?int $nodeId,
        public ?int $clusterId,
        public ?int $generationBasisNodeId,
        public string $domain,
        public string $provenance,
        public string $publication,
        public string $status,
        public ?string $failedStep,
        public ?string $errorCode,
        public ?int $replacesRouteId,
        public ?int $replacedByRouteId,
        public ?string $replacementStep,
        public ?string $targetSetStep,
        public ?RouteTargetData $target,
        /** @var list<RouteTargetData> */
        public array $targets,
        public ?int $processId = null,
        public ?string $upstream = null,
        public ?int $analyticsInstanceId = null,
        /** @var list<RouteRemovalResidueData> */
        public array $retainedOnNodes = [],
    ) {}

    public static function fromRemoval(Route $route): self
    {
        $data = RouteData::fromModel($route);

        return new self(
            id: $data->id,
            kind: $data->kind,
            projectId: $data->projectId,
            nodeId: $data->nodeId,
            clusterId: $data->clusterId,
            generationBasisNodeId: $data->generationBasisNodeId,
            domain: $data->domain,
            provenance: $data->provenance,
            publication: $data->publication,
            status: $data->status,
            failedStep: $data->failedStep,
            errorCode: $data->errorCode,
            replacesRouteId: $data->replacesRouteId,
            replacedByRouteId: $data->replacedByRouteId,
            replacementStep: $data->replacementStep,
            targetSetStep: $data->targetSetStep,
            target: $data->target,
            targets: $data->targets,
            processId: $data->processId,
            upstream: $data->upstream,
            analyticsInstanceId: $data->analyticsInstanceId,
            retainedOnNodes: array_values(RouteRemovalResidue::query()
                ->with('node')
                ->where('route_id', $route->id)
                ->orderBy('node_id')
                ->get()
                ->map(static fn (RouteRemovalResidue $residue): RouteRemovalResidueData => RouteRemovalResidueData::fromModel($residue))
                ->all()),
        );
    }
}
