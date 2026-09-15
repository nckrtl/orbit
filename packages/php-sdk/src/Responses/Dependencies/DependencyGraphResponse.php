<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Dependencies;

final readonly class DependencyGraphResponse
{
    /**
     * @param  list<DependencyResolutionResponse>  $resolutions
     * @param  list<DependencyRequirementResponse>  $requirements
     */
    public function __construct(
        public array $resolutions,
        public array $requirements,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'resolutions' => array_map(static fn (DependencyResolutionResponse $item): array => $item->toArray(), $this->resolutions),
            'requirements' => array_map(static fn (DependencyRequirementResponse $item): array => $item->toArray(), $this->requirements),
        ];
    }
}
