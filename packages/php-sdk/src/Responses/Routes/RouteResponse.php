<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Routes;

use Orbit\Sdk\Support\GatewayErrorCode;
use SensitiveParameter;

final readonly class RouteResponse
{
    public function __construct(
        public int $id,
        public int $appId,
        public ?int $nodeId,
        public ?int $clusterId,
        public ?int $generationBasisNodeId,
        public string $domain,
        public string $provenance,
        public string $publication,
        public string $publicPublication,
        public string $status,
        public ?string $failedStep,
        public ?string $errorCode,
        public ?int $replacesRouteId,
        public ?int $replacedByRouteId,
        public ?string $replacementStep,
        public ?RouteTargetResponse $target,
        public string $requestId,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(
        #[SensitiveParameter]
        array $data,
        #[SensitiveParameter]
        string $requestId,
    ): self {
        $target = self::target($data['target'] ?? null);

        return new self(
            id: is_int($data['id'] ?? null) ? $data['id'] : 0,
            appId: is_int($data['app_id'] ?? null) ? $data['app_id'] : 0,
            nodeId: is_int($data['node_id'] ?? null) ? $data['node_id'] : null,
            clusterId: is_int($data['cluster_id'] ?? null) ? $data['cluster_id'] : null,
            generationBasisNodeId: is_int($data['generation_basis_node_id'] ?? null)
                ? $data['generation_basis_node_id']
                : null,
            domain: is_string($data['domain'] ?? null) ? $data['domain'] : '',
            provenance: is_string($data['provenance'] ?? null) ? $data['provenance'] : '',
            publication: is_string($data['publication'] ?? null) ? $data['publication'] : '',
            publicPublication: is_string($data['public_publication'] ?? null) ? $data['public_publication'] : 'inactive',
            status: is_string($data['status'] ?? null) ? $data['status'] : '',
            failedStep: is_string($data['failed_step'] ?? null) ? $data['failed_step'] : null,
            errorCode: GatewayErrorCode::fromTransport($data['error_code'] ?? null),
            replacesRouteId: is_int($data['replaces_route_id'] ?? null) ? $data['replaces_route_id'] : null,
            replacedByRouteId: is_int($data['replaced_by_route_id'] ?? null)
                ? $data['replaced_by_route_id']
                : null,
            replacementStep: is_string($data['replacement_step'] ?? null) ? $data['replacement_step'] : null,
            target: $target,
            requestId: $requestId,
        );
    }

    /** @return array<string, int|string|null|array{id: int, app_instance_id: int, position: int}> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'app_id' => $this->appId,
            'node_id' => $this->nodeId,
            'cluster_id' => $this->clusterId,
            'generation_basis_node_id' => $this->generationBasisNodeId,
            'domain' => $this->domain,
            'provenance' => $this->provenance,
            'publication' => $this->publication,
            'public_publication' => $this->publicPublication,
            'status' => $this->status,
            'failed_step' => $this->failedStep,
            'error_code' => $this->errorCode,
            'replaces_route_id' => $this->replacesRouteId,
            'replaced_by_route_id' => $this->replacedByRouteId,
            'replacement_step' => $this->replacementStep,
            'target' => $this->target?->toArray(),
            'request_id' => $this->requestId,
        ];
    }

    private static function target(#[SensitiveParameter] mixed $value): ?RouteTargetResponse
    {
        if (! is_array($value)) {
            return null;
        }

        $target = [];

        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                continue;
            }

            $target[$key] = $item;
        }

        return RouteTargetResponse::fromGatewayData($target);
    }
}
