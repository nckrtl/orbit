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
    /** @param list<array{id: string, requirement: string, question: string, true: string, false: string, environment: string}> $verification */
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
        public bool $verificationRequired = false,
        public array $verification = [],
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
            implementerAgentThreadId: $task->implementer_agent_thread_id,
            tokens: $task->tokens,
            lineDiff: $task->line_diff,
            linesAdded: $task->lines_added,
            linesDeleted: $task->lines_deleted,
            durationMs: $task->duration_ms,
            verificationRequired: $task->verification_required,
            verification: $task->verification_criteria ?? [],
        );
    }
}
