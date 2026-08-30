<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Nodes;

use SensitiveParameter;

final readonly class NodeSettings
{
    public function __construct(
        public ?InstanceSettings $instance = null,
        public ?WorktreeSettings $worktree = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(#[SensitiveParameter] array $data): self
    {
        return new self(
            instance: self::nestedInstance($data['instance'] ?? null),
            worktree: self::nestedWorktree($data['worktree'] ?? null),
        );
    }

    /**
     * @return array{
     *     instance: array{path: string|null}|null,
     *     worktree: array{path: string|null}|null
     * }
     */
    public function toArray(): array
    {
        return [
            'instance' => $this->instance?->toArray(),
            'worktree' => $this->worktree?->toArray(),
        ];
    }

    public function isEmpty(): bool
    {
        return $this->instance === null && $this->worktree === null;
    }

    /**
     * @mago-expect analysis:mixed-assignment Gateway nested settings remain mixed until keyed.
     */
    private static function nestedInstance(mixed $value): ?InstanceSettings
    {
        if (! is_array($value)) {
            return null;
        }

        $nested = [];

        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                continue;
            }

            $nested[$key] = $item;
        }

        return InstanceSettings::fromGatewayData($nested);
    }

    /**
     * @mago-expect analysis:mixed-assignment Gateway nested settings remain mixed until keyed.
     */
    private static function nestedWorktree(mixed $value): ?WorktreeSettings
    {
        if (! is_array($value)) {
            return null;
        }

        $nested = [];

        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                continue;
            }

            $nested[$key] = $item;
        }

        return WorktreeSettings::fromGatewayData($nested);
    }
}
