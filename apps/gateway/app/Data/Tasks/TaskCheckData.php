<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use App\Domain\Tasks\TaskCheckStatus;
use App\Models\TaskCheck;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class TaskCheckData extends Data
{
    /** @param list<string> $changedPaths */
    public function __construct(
        public int $id,
        public TaskCheckStatus $status,
        public string $startedAt,
        public ?string $finishedAt,
        public ?int $exitCode,
        public array $changedPaths,
        public ?string $output,
    ) {}

    public static function fromModel(TaskCheck $check): self
    {
        return new self(
            id: $check->id,
            status: $check->status,
            startedAt: $check->started_at->toIso8601String(),
            finishedAt: $check->finished_at?->toIso8601String(),
            exitCode: $check->exit_code,
            changedPaths: $check->changed_paths ?? [],
            output: $check->output,
        );
    }
}
