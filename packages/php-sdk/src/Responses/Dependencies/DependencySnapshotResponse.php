<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Dependencies;

final readonly class DependencySnapshotResponse
{
    public function __construct(
        public string $observedAt,
        public DependencySourceResponse $source,
        public ?DependencyGraphResponse $graph,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'observed_at' => $this->observedAt,
            'source' => $this->source->toArray(),
            'graph' => $this->graph?->toArray(),
        ];
    }
}
