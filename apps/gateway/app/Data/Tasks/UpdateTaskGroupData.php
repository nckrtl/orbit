<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use App\Domain\Tasks\TaskGroupStatus;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class UpdateTaskGroupData extends Data
{
    public function __construct(
        public ?string $title,
        public ?string $brief,
        public ?TaskGroupStatus $status,
    ) {}
}
