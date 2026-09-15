<?php

declare(strict_types=1);

namespace App\Data\Routes;

use Spatie\LaravelData\Data;

final class SetRouteTargetsData extends Data
{
    /**
     * @param  list<int>  $targetIds
     * @param  list<RouteTargetDispositionData>  $dispositions
     */
    public function __construct(
        public array $targetIds,
        public array $dispositions,
    ) {}

    /** @return array{targets: list<int>, dispositions: list<array{app_instance_id: int, route_id?: int, remove?: true}>} */
    public function toIntent(): array
    {
        $targets = $this->targetIds;
        sort($targets);

        $dispositions = array_map(
            static fn (RouteTargetDispositionData $disposition): array => $disposition->toIntent(),
            $this->dispositions,
        );
        usort(
            $dispositions,
            static fn (array $left, array $right): int => $left['app_instance_id'] <=> $right['app_instance_id'],
        );

        return [
            'targets' => array_values($targets),
            'dispositions' => array_values($dispositions),
        ];
    }
}
