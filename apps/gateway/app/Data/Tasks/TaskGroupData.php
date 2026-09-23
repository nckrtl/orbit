<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use App\Domain\Tasks\TaskExecutionMode;
use App\Domain\Tasks\TaskGroupStatus;
use App\Models\AppInstance;
use App\Models\Task;
use App\Models\TaskGroup;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class TaskGroupData extends Data
{
    /**
     * @param  list<TaskData>  $tasks
     */
    public function __construct(
        public int $id,
        public int $appId,
        public string $app,
        public string $projectCode,
        public ?string $taskableType,
        public ?int $taskableId,
        public string $title,
        public string $brief,
        public TaskGroupStatus $status,
        public ?int $reviewerAgentThreadId,
        public ?string $prUrl,
        public bool $notifyCoder,
        public string $implementerModel,
        public string $reviewerModel,
        public ?int $tokens,
        public ?int $lineDiff,
        public ?int $linesAdded,
        public ?int $linesDeleted,
        public ?int $durationMs,
        public array $tasks,
        public TaskExecutionMode $executionMode,
    ) {}

    public static function fromModel(TaskGroup $group): self
    {
        $group->loadMissing(['app', 'tasks']);
        $taskable = $group->taskable;

        return new self(
            executionMode: $group->execution_mode,

            id: $group->id,
            appId: $group->app_id,
            app: $group->app->slug,
            projectCode: $group->app->code,
            taskableType: $taskable instanceof AppInstance ? 'instance' : $group->taskable_type,
            taskableId: $group->taskable_id,
            title: $group->title,
            brief: $group->brief,
            status: $group->status,
            reviewerAgentThreadId: $group->reviewer_agent_thread_id,
            prUrl: $group->pr_url,
            notifyCoder: $group->notify_coder,
            implementerModel: $group->implementer_model,
            reviewerModel: $group->reviewer_model,
            tokens: $group->tokens,
            lineDiff: $group->line_diff,
            linesAdded: $group->lines_added,
            linesDeleted: $group->lines_deleted,
            durationMs: $group->status->isActive() && $group->started_at !== null
                ? max(0, (int) now()->diffInMilliseconds($group->started_at, true))
                : $group->duration_ms,
            tasks: $group->tasks
                ->map(static fn (Task $task): TaskData => TaskData::fromModel($task))
                ->values()
                ->all(),
        );
    }
}
