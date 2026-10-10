<?php

declare(strict_types=1);

namespace App\Infrastructure\Compute;

use App\Domain\Compute\ComputeException;
use App\Domain\Compute\SandboxState;
use App\Domain\Tasks\TaskCompute;
use App\Domain\Tasks\TaskExecutionHold;
use App\Domain\Tasks\TaskExecutionLock;
use App\Domain\Tasks\TaskExecutionMode;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskStatus;
use App\Models\Instance;
use App\Models\Task;
use App\Models\TaskSandbox;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/** Reconcile power under the same admission lock used to start and end task work. */
final readonly class TaskSandboxGroupLifecycle
{
    private const string WaitPrefix = 'Sandbox compute: ';

    public function __construct(private TaskExecutionLock $execution, private TaskSandboxDrivers $drivers, private TaskSandboxLifecycle $lifecycle) {}

    public function review(Task $group): void
    {
        if (! self::orbitLane($group)) {
            return;
        }
        $this->execution->synchronized($group->id, function () use ($group): void {
            $group->refresh();
            if ($group->execution_mode !== TaskExecutionMode::Managed || TaskExecutionHold::active($group)
                || $group->status !== TaskGroupStatus::WaitingForReview || ! is_string($group->pr_url) || $group->pr_url === ''
                || $group->tasks()->whereIn('status', [TaskStatus::Todo, TaskStatus::Reserved, TaskStatus::Running, TaskStatus::Reviewing])->exists()) {
                return;
            }
            try {
                $sandbox = $this->sandbox($group);
                $this->lifecycle->review($sandbox, $this->drivers->forSandbox($sandbox), $group->preview ?? false, $this->capacityWaiting($group));
                $this->clearWait($group);
            } catch (Throwable $exception) {
                $this->wait($group, $exception);
            }
        });
    }

    /** A failed restore never admits a fetch, an implementer, or a reviewer. */
    public function resume(Task $group): bool
    {
        if (! self::orbitLane($group)) {
            return true;
        }

        return $this->execution->synchronized($group->id, function () use ($group): bool {
            $group->refresh();
            if ($group->execution_mode !== TaskExecutionMode::Managed || TaskExecutionHold::active($group)
                || ! in_array($group->status, [TaskGroupStatus::Running, ...TaskGroupStatus::awaitingCompletion()], true)) {
                return false;
            }
            try {
                $sandbox = $this->sandbox($group);
                $result = $this->lifecycle->activate($sandbox, $this->drivers->forSandbox($sandbox));
                if ($result->state !== SandboxState::Running || $result->desired_power !== 'running') {
                    throw new ComputeException('compute.starting', 'The sandbox is still starting.');
                }
                $this->clearWait($group);

                return true;
            } catch (Throwable $exception) {
                $this->wait($group, $exception);

                return false;
            }
        });
    }

    /** Only Orbit-lane sandboxes park and resume. A task VM stays up until its group ends. */
    private static function orbitLane(Task $group): bool
    {
        return $group->task_compute === TaskCompute::Vm && $group->project->slug === 'orbit';
    }

    private function sandbox(Task $group): TaskSandbox
    {
        $group->load(['project', 'taskable']);
        $workspace = $group->taskable;
        $sandbox = $workspace instanceof Instance ? $workspace->taskSandbox : null;
        if (! $workspace instanceof Instance || ! $sandbox instanceof TaskSandbox || $sandbox->group_id !== $group->id
            || $workspace->project_id !== $group->project_id || $group->parent_id !== null || $group->task_compute !== TaskCompute::Vm) {
            throw new ComputeException('compute.ownership_mismatch', 'The task has no matching sandbox workspace.');
        }
        $expectedNode = $sandbox->provider === 'incus' ? ($sandbox->spec['host_id'] ?? null) : null;
        if ($expectedNode === null || $workspace->node_id !== $expectedNode || $sandbox->node_id !== null) {
            throw new ComputeException('compute.ownership_mismatch', 'The task workspace does not match its sandbox Node.');
        }

        return $sandbox;
    }

    private function capacityWaiting(Task $group): bool
    {
        return Task::topLevel()->where('execution_mode', TaskExecutionMode::Managed)
            ->whereKeyNot($group->id)->whereNull('watched_pr_completion')
            ->whereIn('status', [TaskGroupStatus::Todo, TaskGroupStatus::WaitingForReview])
            ->whereHas('tasks', fn ($tasks) => $tasks->where('status', TaskStatus::Todo))
            ->where(function (Builder $query): void {
                $query->where('task_compute', TaskCompute::Vm)->orWhere(function (Builder $unclaimed): void {
                    $unclaimed->whereNull('task_compute')->whereHas('project', fn ($project) => $project->where('task_compute', TaskCompute::Vm));
                });
            })->get(['id', 'parent_id', 'assistance_requested', 'assistance_reason'])
            ->contains(static fn (Task $candidate): bool => ! TaskScheduler::resumeBlocked($candidate));
    }

    private function clearWait(Task $group): void
    {
        if (is_string($group->capacity_wait_reason) && str_starts_with($group->capacity_wait_reason, self::WaitPrefix)) {
            $group->update(['capacity_wait_reason' => null]);
        }
    }

    private function wait(Task $group, Throwable $exception): void
    {
        $reason = $exception instanceof ComputeException ? $exception->getMessage() : 'The sandbox operation could not be confirmed. Orbit will retry.';
        $group->update(['capacity_wait_reason' => self::WaitPrefix.$reason]);
    }
}
