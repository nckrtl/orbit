<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use App\Domain\Tasks\TaskStatus;
use App\Models\Task;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class TaskData extends Data
{
    public function __construct(
        public int $id,
        public int $taskGroupId,
        public int $position,
        public string $title,
        public string $brief,
        public TaskStatus $status,
        public ?string $implementerThreadId,
        public ?int $tokens,
        public ?int $lineDiff,
        public ?int $durationMs,
    ) {}

    public static function fromModel(Task $task): self
    {
        return new self(
            id: $task->id,
            taskGroupId: $task->task_group_id,
            position: $task->position,
            title: $task->title,
            brief: $task->brief,
            status: $task->status,
            implementerThreadId: $task->implementer_thread_id,
            tokens: $task->tokens,
            lineDiff: $task->line_diff,
            durationMs: $task->duration_ms,
        );
    }
}
