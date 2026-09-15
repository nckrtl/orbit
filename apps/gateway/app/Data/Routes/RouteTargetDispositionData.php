<?php

declare(strict_types=1);

namespace App\Data\Routes;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class RouteTargetDispositionData extends Data
{
    public function __construct(
        public int $appInstanceId,
        public ?int $routeId = null,
        public bool $remove = false,
    ) {}

    /** @return array{app_instance_id: int, route_id?: int, remove?: true} */
    public function toIntent(): array
    {
        $intent = ['app_instance_id' => $this->appInstanceId];

        if ($this->routeId !== null) {
            $intent['route_id'] = $this->routeId;
        }

        if ($this->remove) {
            $intent['remove'] = true;
        }

        return $intent;
    }
}
