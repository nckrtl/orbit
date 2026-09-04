<?php

declare(strict_types=1);

namespace App\Data\Routes;

use App\Models\RouteTarget;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class RouteTargetData extends Data
{
    public function __construct(
        public int $id,
        public int $appInstanceId,
        public int $position,
    ) {}

    public static function fromModel(RouteTarget $target): self
    {
        return new self(
            id: $target->id,
            appInstanceId: $target->app_instance_id,
            position: $target->position,
        );
    }
}
