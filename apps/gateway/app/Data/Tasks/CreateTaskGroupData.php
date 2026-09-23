<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use App\Domain\Tasks\TaskGroupStatus;
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
        public TaskGroupStatus $status,
        public bool $notifyCoder,
        public bool $plan,
        public array $tasks,
    ) {}
}
