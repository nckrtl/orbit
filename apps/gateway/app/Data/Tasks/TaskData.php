<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskType;
use App\Models\Task;
use App\Models\TaskCheck;
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
        public ?int $implementerAgentThreadId,
        public ?int $tokens,
        public ?int $lineDiff,
        public ?int $linesAdded,
        public ?int $linesDeleted,
        public ?int $durationMs,
        public TaskType $type,
        public ?string $targetThreadId,
        public ?string $completionSummary,
        public ?TaskCheckData $check,
    ) {}

    public static function fromModel(Task $task): self
    {
        $check = $task->checks()->latest('id')->first();

        return new self(
            type: $task->type,
            targetThreadId: $task->target_thread_id,
            completionSummary: $task->completion_summary,
            check: $check instanceof TaskCheck ? TaskCheckData::fromModel($check) : null,

            id: $task->id,
            taskGroupId: $task->task_group_id,
            position: $task->position,
            title: $task->title,
            brief: $task->brief,
            status: $task->status,
            implementerAgentThreadId: $task->implementer_agent_thread_id,
            tokens: $task->tokens,
            lineDiff: $task->line_diff,
            linesAdded: $task->lines_added,
            linesDeleted: $task->lines_deleted,
            durationMs: $task->duration_ms,
        );
    }
}
