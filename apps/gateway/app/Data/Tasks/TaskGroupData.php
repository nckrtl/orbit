<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use App\Domain\Compute\SandboxPower;
use App\Domain\Compute\SandboxState;
use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\TaskCompute;
use App\Domain\Tasks\TaskExecutionMode;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\TaskVms\TaskVmState;
use App\Models\Instance;
use App\Models\Task;
use App\Models\TaskSandbox;
use App\Models\TaskVm;
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
        public int $projectId,
        public string $project,
        public string $projectCode,
        public ?string $taskableType,
        public ?int $taskableId,
        public string $title,
        public string $brief,
        public TaskGroupStatus $status,
        public ?int $reviewerAgentThreadId,
        public ?string $prUrl,
        public ?string $watchedPrUrl,
        public ?int $watchedPrNumber,
        public ?string $watchedPrState,
        public bool $notifyCoder,
        public bool $assistanceRequested,
        public ?AssistanceKind $assistanceKind,
        public ?string $assistanceQuestion,
        public ?string $assistanceReason,
        public string $implementerModel,
        public string $reviewerModel,
        public ?int $tokens,
        public ?int $lineDiff,
        public ?int $linesAdded,
        public ?int $linesDeleted,
        public ?int $durationMs,
        public int $questions,
        public int $escalations,
        public array $tasks,
        public TaskExecutionMode $executionMode,
        public ?TaskCompute $taskCompute = null,
        public ?string $capacityWaitReason = null,
        public ?SandboxPower $sandboxPower = null,
        public bool $preview = false,
        public ?TaskReviewAndMergeData $reviewAndMerge = null,
    ) {}

    /** @return array<string, mixed> */
    public function toCompactArray(): array
    {
        $data = $this->toArray();
        unset($data['brief'], $data['assistance_question'], $data['assistance_reason']);
        $data['tasks'] = array_map(static fn (TaskData $task): array => $task->toCompactArray(), $this->tasks);

        return $data;
    }

    public static function fromModel(Task $group): self
    {
        $group->loadMissing(['project', 'tasks']);
        $taskable = $group->taskable;
        $status = $group->groupStatus();

        return new self(
            executionMode: $group->execution_mode,
            taskCompute: $group->task_compute,
            capacityWaitReason: $group->capacity_wait_reason,
            sandboxPower: self::sandboxPower($group),
            preview: $group->preview ?? false,
            reviewAndMerge: $group->execution_mode === TaskExecutionMode::Managed ? TaskReviewAndMergeData::fromModel($group) : null,

            id: $group->id,
            projectId: $group->project_id,
            project: $group->project->slug,
            projectCode: $group->project->code,
            taskableType: $taskable instanceof Instance ? 'instance' : $group->taskable_type,
            taskableId: $group->taskable_id,
            title: $group->title,
            brief: $group->brief,
            status: $status,
            reviewerAgentThreadId: $group->reviewer_agent_thread_id,
            prUrl: $group->pr_url,
            watchedPrUrl: $group->watched_pr_url,
            watchedPrNumber: $group->watched_pr_number,
            watchedPrState: $group->watched_pr_state,
            notifyCoder: $group->notify_coder,
            assistanceRequested: $group->assistance_requested,
            assistanceKind: $group->assistance_kind,
            assistanceQuestion: $group->assistance_question,
            assistanceReason: $group->assistance_reason,
            implementerModel: $group->implementer_model,
            reviewerModel: $group->reviewer_model,
            tokens: $group->tokens,
            lineDiff: $group->line_diff,
            linesAdded: $group->lines_added,
            linesDeleted: $group->lines_deleted,
            durationMs: $status->isActive() && $group->started_at !== null
                ? max(0, (int) now()->diffInMilliseconds($group->started_at, true))
                : $group->duration_ms,
            questions: (int) ($group->questions ?? 0),
            escalations: (int) ($group->escalations ?? 0),
            tasks: array_values($group->tasks
                ->map(static fn (Task $task): TaskData => TaskData::fromModel($task))
                ->all()),
        );
    }

    private static function sandboxPower(Task $group): ?SandboxPower
    {
        if ($group->task_compute !== TaskCompute::Vm || $group->parent_id !== null) {
            return null;
        }
        // A task VM reports running while ready and destroyed once gone. The capacity wait reason names other states.
        $vm = TaskVm::query()->where('group_id', $group->id)->orderByDesc('id')->first();
        if ($vm instanceof TaskVm) {
            return match ($vm->state) {
                TaskVmState::Ready => SandboxPower::Running,
                TaskVmState::Destroyed => SandboxPower::Destroyed,
                default => null,
            };
        }
        $workspace = $group->taskable;
        if (! $workspace instanceof Instance) {
            if ($group->taskable_id !== null) {
                return null;
            }
            $history = TaskSandbox::query()->where('group_id', $group->id)->get(['state', 'destroyed_at']);

            return $history->isNotEmpty() && $history->every(static fn (TaskSandbox $sandbox): bool => $sandbox->state === SandboxState::Destroyed && $sandbox->destroyed_at !== null)
                ? SandboxPower::Destroyed : null;
        }
        $sandbox = $workspace->taskSandbox;
        if (! $sandbox instanceof TaskSandbox || $sandbox->group_id !== $group->id || $workspace->project_id !== $group->project_id) {
            return null;
        }
        $expectedNode = $sandbox->provider === 'incus' ? ($sandbox->spec['host_id'] ?? null) : null;
        if ($expectedNode === null || $workspace->node_id !== $expectedNode || $sandbox->node_id !== null) {
            return null;
        }

        return SandboxPower::tryFrom($sandbox->state->value);
    }
}
