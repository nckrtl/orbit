<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\AppInstances;

use SensitiveParameter;

/**
 * @mago-expect lint:cyclomatic-complexity The DTO bounds every AppInstance response field in one factory.
 * @mago-expect lint:excessive-parameter-list The DTO exposes the complete bounded AppInstance response.
 */
final readonly class AppInstanceResponse
{
    public function __construct(
        public int $id,
        public int $appId,
        public int $nodeId,
        public string $name,
        public string $environment,
        public string $sourceKind,
        public string $checkoutPath,
        public ?string $root,
        public ?string $effectiveRoot,
        public ?string $selectedBranch,
        public ?string $startingCommit,
        public string $status,
        public string $requestId,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(
        #[SensitiveParameter]
        array $data,
        #[SensitiveParameter]
        string $requestId,
    ): self {
        return new self(
            id: is_int($data['id'] ?? null) ? $data['id'] : 0,
            appId: is_int($data['app_id'] ?? null) ? $data['app_id'] : 0,
            nodeId: is_int($data['node_id'] ?? null) ? $data['node_id'] : 0,
            name: is_string($data['name'] ?? null) ? $data['name'] : '',
            environment: is_string($data['environment'] ?? null) ? $data['environment'] : '',
            sourceKind: is_string($data['source_kind'] ?? null) ? $data['source_kind'] : '',
            checkoutPath: is_string($data['checkout_path'] ?? null) ? $data['checkout_path'] : '',
            root: is_string($data['root'] ?? null) ? $data['root'] : null,
            effectiveRoot: is_string($data['effective_root'] ?? null) ? $data['effective_root'] : null,
            selectedBranch: is_string($data['selected_branch'] ?? null) ? $data['selected_branch'] : null,
            startingCommit: is_string($data['starting_commit'] ?? null) ? $data['starting_commit'] : null,
            status: is_string($data['status'] ?? null) ? $data['status'] : '',
            requestId: $requestId,
        );
    }

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'app_id' => $this->appId,
            'node_id' => $this->nodeId,
            'name' => $this->name,
            'environment' => $this->environment,
            'source_kind' => $this->sourceKind,
            'checkout_path' => $this->checkoutPath,
            'root' => $this->root,
            'effective_root' => $this->effectiveRoot,
            'selected_branch' => $this->selectedBranch,
            'starting_commit' => $this->startingCommit,
            'status' => $this->status,
            'request_id' => $this->requestId,
        ];
    }
}
