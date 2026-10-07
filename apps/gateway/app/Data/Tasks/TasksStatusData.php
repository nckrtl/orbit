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
     * @param  string|null  $lastTickAt  When the latest `tasks:tick` started its work, or null when no tick is remembered.
     */
    public function __construct(
        public bool $enabled,
        public array $assistance,
        public ?string $lastTickAt,
    ) {}
}
