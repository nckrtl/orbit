<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Nodes;

use SensitiveParameter;

final readonly class NodeSettings
{
    public function __construct(
        public ?AppsSettings $apps = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(#[SensitiveParameter] array $data): self
    {
        return new self(
            apps: self::nestedApps($data['apps'] ?? null),
        );
    }

    /**
     * @return array{apps: array{path: string|null}|null}
     */
    public function toArray(): array
    {
        return [
            'apps' => $this->apps?->toArray(),
        ];
    }

    public function isEmpty(): bool
    {
        return $this->apps === null;
    }

    /**
     * @mago-expect analysis:mixed-assignment Gateway nested settings remain mixed until keyed.
     */
    private static function nestedApps(mixed $value): ?AppsSettings
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

        return AppsSettings::fromGatewayData($nested);
    }
}
