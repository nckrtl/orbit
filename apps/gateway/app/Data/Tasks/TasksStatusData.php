<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class TasksStatusData extends Data
{
    /**
     * @param  list<TaskAssistanceData>  $assistance
     */
    public function __construct(
        public bool $enabled,
        public array $assistance,
    ) {}
}
