<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Routes;

use SensitiveParameter;

/**
 * @mago-expect lint:cyclomatic-complexity Gateway Route fields are validated independently.
 * @mago-expect lint:excessive-parameter-list The DTO exposes the bounded Route contract.
 */
final readonly class RouteResponse
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
            hostname: is_string($data['hostname'] ?? null) ? $data['hostname'] : '',
            provenance: is_string($data['provenance'] ?? null) ? $data['provenance'] : '',
            publication: is_string($data['publication'] ?? null) ? $data['publication'] : '',
            status: is_string($data['status'] ?? null) ? $data['status'] : '',
            failedStep: is_string($data['failed_step'] ?? null) ? $data['failed_step'] : null,
            errorCode: is_string($data['error_code'] ?? null) ? $data['error_code'] : null,
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
            'hostname' => $this->hostname,
            'provenance' => $this->provenance,
            'publication' => $this->publication,
            'status' => $this->status,
            'failed_step' => $this->failedStep,
            'error_code' => $this->errorCode,
            'target' => $this->target?->toArray(),
            'request_id' => $this->requestId,
        ];
    }

    /** @mago-expect analysis:mixed-assignment Gateway values remain mixed until their string keys are retained. */
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
