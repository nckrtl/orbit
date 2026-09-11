<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\DeploymentLayout;

use App\Domain\Shared\ResourceOperationException;

final readonly class DeploymentLayoutInventory
{
    public function __construct(
        public string $sourcePath,
        public string $releasePath,
        public string $sourceIdentity,
        public string $gitHead,
        public string $gitStateHash,
        public string $environmentHash,
        public ?string $sqliteSourcePath,
        public ?string $sqliteHash,
        public ?string $sqliteIdentity,
        public string $localTuningHash,
        public int $routeId,
        public int $routeNodeId,
        public string $routeHostname,
        public string $routeStatus,
        public string $routeTargetsHash,
        public string $documentRoot,
        public string $previousSocket,
    ) {}

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return [
            'source_path' => $this->sourcePath,
            'release_path' => $this->releasePath,
            'source_identity' => $this->sourceIdentity,
            'git_head' => $this->gitHead,
            'git_state_hash' => $this->gitStateHash,
            'environment_hash' => $this->environmentHash,
            'sqlite_source_path' => $this->sqliteSourcePath,
            'sqlite_hash' => $this->sqliteHash,
            'sqlite_identity' => $this->sqliteIdentity,
            'local_tuning_hash' => $this->localTuningHash,
            'route_id' => $this->routeId,
            'route_node_id' => $this->routeNodeId,
            'route_hostname' => $this->routeHostname,
            'route_status' => $this->routeStatus,
            'route_targets_hash' => $this->routeTargetsHash,
            'document_root' => $this->documentRoot,
            'previous_socket' => $this->previousSocket,
        ];
    }

    /** @param array<string, mixed> $values */
    public static function fromArray(array $values): self
    {
        $routeId = $values['route_id'] ?? null;

        if (! is_int($routeId) || $routeId < 1) {
            throw self::invalid();
        }

        $routeNodeId = $values['route_node_id'] ?? null;

        if (! is_int($routeNodeId) || $routeNodeId < 1) {
            throw self::invalid();
        }

        return new self(
            sourcePath: self::string($values, 'source_path'),
            releasePath: self::string($values, 'release_path'),
            sourceIdentity: self::string($values, 'source_identity'),
            gitHead: self::string($values, 'git_head'),
            gitStateHash: self::string($values, 'git_state_hash'),
            environmentHash: self::string($values, 'environment_hash'),
            sqliteSourcePath: self::nullableString($values, 'sqlite_source_path'),
            sqliteHash: self::nullableString($values, 'sqlite_hash'),
            sqliteIdentity: self::nullableString($values, 'sqlite_identity'),
            localTuningHash: self::string($values, 'local_tuning_hash'),
            routeId: $routeId,
            routeNodeId: $routeNodeId,
            routeHostname: self::string($values, 'route_hostname'),
            routeStatus: self::string($values, 'route_status'),
            routeTargetsHash: self::string($values, 'route_targets_hash'),
            documentRoot: self::string($values, 'document_root'),
            previousSocket: self::string($values, 'previous_socket'),
        );
    }

    /** @param array<string, mixed> $values */
    private static function string(array $values, string $key): string
    {
        $value = $values[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw self::invalid();
        }

        return $value;
    }

    /** @param array<string, mixed> $values */
    private static function nullableString(array $values, string $key): ?string
    {
        $value = $values[$key] ?? null;

        if ($value !== null && (! is_string($value) || $value === '')) {
            throw self::invalid();
        }

        return $value;
    }

    private static function invalid(): ResourceOperationException
    {
        return new ResourceOperationException(
            'deployment_layout.inventory_invalid',
            'The recorded deployment-layout inventory is invalid.',
            409,
        );
    }
}
