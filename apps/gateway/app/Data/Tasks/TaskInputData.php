<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class TaskInputData extends Data
{
    /** @param list<array{id: string, requirement: string, question: string, true: string, false: string, environment: string}> $verification */
    public function __construct(
        public string $title,
        public string $brief,
        public array $verification = [],
    ) {}
}
