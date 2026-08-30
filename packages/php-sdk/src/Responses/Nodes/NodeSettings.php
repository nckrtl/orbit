<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Nodes;

use SensitiveParameter;

final readonly class NodeSettings
{
    public function __construct(
        public ?string $instancePath = null,
        public ?string $worktreePath = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(#[SensitiveParameter] array $data): self
    {
        $instance = is_array($data['instance'] ?? null) ? $data['instance'] : null;
        $worktree = is_array($data['worktree'] ?? null) ? $data['worktree'] : null;

        return new self(
            instancePath: is_string($instance['path'] ?? null) ? $instance['path'] : null,
            worktreePath: is_string($worktree['path'] ?? null) ? $worktree['path'] : null,
        );
    }

    /**
     * @return array{
     *     instance: array{path: string}|null,
     *     worktree: array{path: string}|null
     * }
     */
    public function toArray(): array
    {
        return [
            'instance' => $this->instancePath === null ? null : ['path' => $this->instancePath],
            'worktree' => $this->worktreePath === null ? null : ['path' => $this->worktreePath],
        ];
    }

    public function isEmpty(): bool
    {
        return $this->instancePath === null && $this->worktreePath === null;
    }
}
