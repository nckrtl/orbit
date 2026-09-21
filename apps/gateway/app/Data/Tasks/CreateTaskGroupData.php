<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class CreateTaskGroupData extends Data
{
    /**
     * @param  list<TaskInputData>  $tasks
     */
    public function __construct(
        public int $appId,
        public string $title,
        public string $brief,
        public bool $notifyCoder,
        public array $tasks,
    ) {}
}
