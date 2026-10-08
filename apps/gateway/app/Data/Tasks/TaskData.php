<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\TaskDeliverable;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskTopology;
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
        /** @var list<array<string, string|bool|list<string>>> */
        public array $deliverables,
        /** @var list<string> */
        public array $topology,
        public TaskStatus $status,
        public ?int $implementerAgentThreadId,
        public ?int $tokens,
        public ?int $lineDiff,
        public ?int $linesAdded,
        public ?int $linesDeleted,
        public ?int $durationMs,
        public int $questions,
        public int $escalations,
        public TaskType $type,
        public ?string $targetThreadId,
        public ?string $completionSummary,
        public ?TaskCheckData $check,
        public bool $assistanceRequested,
        public ?AssistanceKind $assistanceKind,
        public ?string $assistanceQuestion,
        public ?string $assistanceReason,
        public ?string $fixupProblem,
    ) {}

    public static function fromModel(Task $task): self
    {
        $check = $task->checks()->latest('id')->first();

        return new self(
            type: $task->type,
            targetThreadId: $task->target_thread_id,
            completionSummary: $task->completion_summary,
            check: $check instanceof TaskCheck ? TaskCheckData::fromModel($check) : null,
            assistanceRequested: $task->assistance_requested,
            assistanceKind: $task->assistance_kind,
            assistanceQuestion: $task->assistance_question,
            assistanceReason: $task->assistance_reason,
            fixupProblem: $task->fixup_problem,

            id: $task->id,
            taskGroupId: $task->requireGroupId(),
            position: $task->position,
            title: $task->title,
            brief: $task->brief,
            deliverables: array_map(static fn (TaskDeliverable $deliverable): array => $deliverable->toArray(), $task->deliverableList()),
            topology: TaskTopology::from($task->topology ?? []),
            status: $task->subtaskStatus(),
            implementerAgentThreadId: $task->implementer_agent_thread_id,
            tokens: $task->tokens,
            lineDiff: $task->line_diff,
            linesAdded: $task->lines_added,
            linesDeleted: $task->lines_deleted,
            durationMs: $task->duration_ms,
            questions: (int) ($task->questions ?? 0),
            escalations: (int) ($task->escalations ?? 0),
        );
    }
}
