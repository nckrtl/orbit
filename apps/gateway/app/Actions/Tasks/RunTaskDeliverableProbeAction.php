<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskCheckException;
use App\Domain\Tasks\TaskCheckKind;
use App\Domain\Tasks\TaskCheckReading;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskCommentType;
use App\Domain\Tasks\TaskDeliverable;
use App\Domain\Tasks\TaskDeliverableType;
use App\Domain\Tasks\TaskExecutionHold;
use App\Domain\Tasks\TaskExecutionLock;
use App\Domain\Tasks\TaskExecutionMode;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskReviewBase;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskThreadRole;
use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskComment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final readonly class RunTaskDeliverableProbeAction
{
    public function __construct(private RequireTasksExtensionAction $requireExtension, private TaskCheckRunner $checks) {}

    /** A null thread denotes an operator; transport boundaries authenticate that caller. */
    public function execute(Task $group, Task $task, string $deliverableId, bool $base = false, ?AgentThread $thread = null): TaskCheck
    {
        $this->requireExtension->execute();
        $check = TaskExecutionHold::run($group, function () use ($group, $task, $deliverableId, $base, $thread): TaskCheck {
            $claim = DB::transaction(function () use ($group, $task, $deliverableId, $base, $thread): TaskCheck {
                $group = Task::topLevel()->lockForUpdate()->findOrFail($group->id);
                $task = Task::query()->lockForUpdate()->findOrFail($task->id);
                $instance = $group->taskable;
                if ($task->parent_id !== $group->id || $task->status !== TaskStatus::Running
                    || $group->status !== TaskGroupStatus::Running || $group->execution_mode !== TaskExecutionMode::Managed
                    || ! $instance instanceof Instance) {
                    $this->refuse('not_running', 'Deliverable probes require a running subtask and workspace.', 409);
                }
                if ($thread !== null && ($thread->id !== $task->implementer_agent_thread_id
                    || $thread->task_group_id !== $group->id || $thread->task_id !== $task->id
                    || $thread->role !== TaskThreadRole::Implementer->value)) {
                    $this->refuse('wrong_thread', 'Only the running subtask implementer or an operator can request a probe.', 403);
                }
                $deliverable = null;
                foreach ($task->deliverableList() as $candidate) {
                    if ($candidate->id === $deliverableId) {
                        $deliverable = $candidate;
                        break;
                    }
                }
                if ($deliverable === null) {
                    $this->refuse('not_found', 'The deliverable was not found.', 404);
                }
                if ($deliverable->type !== TaskDeliverableType::Command) {
                    $this->refuse('not_command', 'Only declared command deliverables can be probed.', 422);
                }
                if (TaskCheck::query()->whereIn('task_id', $group->tasks()->select('id'))
                    ->where('status', TaskCheckStatus::Running->value)->exists()) {
                    $this->refuse('check_running', 'A check is already running for this group.', 409);
                }
                if (TaskComment::query()->where('task_id', $task->id)->where('type', TaskCommentType::DeliverableProbe->value)
                    ->where('completion_attempt', $task->completion_attempt)->count() >= 3) {
                    $this->refuse('limit', 'At most three probes are allowed per completion attempt.', 429);
                }
                $command = ['id' => $deliverable->id, 'command' => $deliverable->command, 'directory' => $deliverable->directory];
                if ($base) {
                    $declared = $deliverable->toArray();
                    if (array_key_exists('fails_on_base', $declared)) {
                        $command['fails_on_base'] = $deliverable->fails_on_base;
                    }
                    if (array_key_exists('paths', $declared)) {
                        $command['paths'] = $deliverable->paths;
                    }
                }
                $start = TaskReviewBase::commit($task);
                $comment = TaskComment::query()->create([
                    'task_group_id' => $group->id, 'task_id' => $task->id, 'agent_thread_id' => $thread?->id,
                    'completion_attempt' => $task->completion_attempt, 'type' => TaskCommentType::DeliverableProbe,
                    'author' => $thread === null ? 'operator' : 'implementer', 'posted_at' => now(),
                    'body' => json_encode(['kind' => 'probe', 'deliverable' => $deliverable->id,
                        'command' => $deliverable->command, 'directory' => $deliverable->directory], JSON_THROW_ON_ERROR),
                ]);

                return TaskCheck::query()->create([
                    'task_id' => $task->id, 'task_comment_id' => $comment->id, 'kind' => TaskCheckKind::Probe,
                    'status' => TaskCheckStatus::Running, 'pid' => 0, 'process_started' => '',
                    'head_before' => '', 'tree_before' => '', 'started_at' => now(),
                    'deliverable_evidence' => ['start' => $start !== '' ? $start : null, 'commands' => [$command]],
                ]);
            });
            // The reservation must commit before remote work. Never erase it on an ambiguous start.
            try {
                $this->startReserved($group, $claim);
            } catch (TaskCheckException $exception) {
                throw new ResourceOperationException(errorCode: 'tasks.probe_start_pending',
                    message: 'The probe is reserved; its start will be recovered: '.$exception->getMessage(), status: 502,
                    previous: $exception, details: ['check_id' => $claim->id]);
            }

            return $claim->refresh();
        });

        return $check ?? $this->refuse('not_running', 'The task group no longer admits work.', 409);
    }

    /** Polls only probes. No rubric, reminder, attempt, or assistance transition belongs here. */
    public function reconcile(Task $group): void
    {
        $instance = $group->taskable;
        if (! $instance instanceof Instance) {
            return;
        }
        foreach (TaskCheck::query()->whereIn('task_id', $group->tasks()->select('id'))
            ->where('kind', TaskCheckKind::Probe->value)->where('status', TaskCheckStatus::Running->value)->get() as $check) {
            try {
                if ($check->pid === 0) {
                    $this->startReserved($group, $check);
                    $check->refresh();
                }
                if ($check->status !== TaskCheckStatus::Running) {
                    continue;
                }
                $reading = $this->checks->read($instance, $check->process());
            } catch (TaskCheckException) {
                continue;
            }
            if ($reading->state === 'running') {
                continue;
            }
            $this->recordReading($check, $reading);
        }
    }

    /** Cleanup only: a held group must never enter startReserved, even to recover a missing identity. */
    public function retireHeld(Task $group): void
    {
        app(TaskExecutionLock::class)->synchronized($group->id, function () use ($group): void {
            $group = $group->fresh(['taskable']) ?? $group;
            if (! TaskExecutionHold::active($group)) {
                return;
            }
            $checks = TaskCheck::query()->whereIn('task_id', $group->tasks()->select('id'))
                ->where('kind', TaskCheckKind::Probe->value)->where('status', TaskCheckStatus::Running->value)->get();
            foreach ($checks as $check) {
                try {
                    $instance = $group->taskable;
                    if (! $instance instanceof Instance) {
                        throw new TaskCheckException('The held probe workspace is unavailable.');
                    }
                    $retirement = $this->checks->retireProbe($instance, 'probe-'.$check->id);
                    $process = $retirement->process;
                    if ($process === null) {
                        $this->recordReading($check, TaskCheckReading::lost('Probe retired before remote start because the watched pull request ended.', $retirement->execution), cancelled: true);

                        continue;
                    }
                    TaskCheck::query()->whereKey($check->id)->where('status', TaskCheckStatus::Running->value)->update([
                        'pid' => $process->pid, 'process_started' => $process->started,
                        'head_before' => $process->head, 'tree_before' => $process->tree,
                    ]);
                    $reading = $this->checks->read($instance, $process);
                    if ($reading->state === 'finished') {
                        $this->recordReading($check, $reading);

                        continue;
                    }
                    $this->checks->cancel($instance, $process);
                    $this->recordReading($check, TaskCheckReading::lost(
                        $reading->output."\nProbe stopped because the watched pull request ended.", $retirement->execution), cancelled: true);
                } catch (TaskCheckException $exception) {
                    throw new ResourceOperationException(errorCode: 'tasks.probe_retirement_pending',
                        message: 'The held probe must be retired before workspace cleanup: '.$exception->getMessage(),
                        status: 502, previous: $exception, details: ['check_id' => $check->id]);
                }
            }
        });
    }

    private function recordReading(TaskCheck $check, TaskCheckReading $reading, bool $cancelled = false): void
    {
        DB::transaction(function () use ($check, $reading, $cancelled): void {
            $locked = TaskCheck::query()->lockForUpdate()->findOrFail($check->id);
            if ($locked->status !== TaskCheckStatus::Running) {
                return;
            }
            $finished = $reading->finishedAt === null ? now() : Carbon::createFromTimestamp($reading->finishedAt);
            $comment = TaskComment::query()->findOrFail($locked->task_comment_id);
            $receipt = json_decode($comment->body, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($receipt) || ! is_string($receipt['deliverable'] ?? null)) {
                throw new \UnexpectedValueException('The probe request receipt is invalid.');
            }
            $commands = $reading->deliverables['commands'] ?? null;
            $evidence = is_array($commands) ? ($commands[$receipt['deliverable']] ?? null) : null;
            $exitCode = is_array($evidence) && is_int($evidence['exit_code'] ?? null) ? $evidence['exit_code'] : $reading->exitCode;
            $locked->update([
                'status' => match (true) {
                    $cancelled => TaskCheckStatus::Cancelled,
                    $reading->state === 'lost' => TaskCheckStatus::Lost,
                    $exitCode === 0 => TaskCheckStatus::Passed,
                    default => TaskCheckStatus::Failed,
                },
                'head_after' => $reading->headAfter, 'tree_after' => $reading->treeAfter,
                'exit_code' => $exitCode, 'output' => $reading->output,
                'changed_paths' => $reading->changedPaths, 'failed_step' => $reading->failedStep,
                'deliverable_evidence' => $reading->deliverables, 'finished_at' => $finished,
            ]);
            $receipt += [
                'check_id' => $locked->id, 'managed_user' => $reading->execution['managed_user'] ?? null,
                'uid' => $reading->execution['uid'] ?? null, 'tmpdir' => $reading->execution['tmpdir'] ?? null,
                'head' => $reading->headAfter ?? $locked->head_before, 'tree' => $reading->treeAfter ?? $locked->tree_before,
                'exit_code' => $exitCode, 'output_tail' => mb_strcut($reading->output, -16384, null, 'UTF-8'),
                'started_at' => $locked->started_at->toIso8601String(), 'finished_at' => $finished->toIso8601String(),
            ];
            if (is_array($evidence) && array_key_exists('base_exit_code', $evidence)) {
                $receipt['base_exit_code'] = $evidence['base_exit_code'];
            }
            $comment->update(['body' => json_encode($receipt, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)]);
        });
    }

    private function startReserved(Task $group, TaskCheck $claim): void
    {
        $instance = $group->taskable;
        if (! $instance instanceof Instance) {
            throw new TaskCheckException('The probe workspace is unavailable.');
        }
        $process = $this->checks->start($instance, command: '', setup: [], deliverables: $this->reservedPayload($claim), key: 'probe-'.$claim->id);
        $updated = TaskCheck::query()->whereKey($claim->id)->where('status', TaskCheckStatus::Running->value)->where('pid', 0)->update([
            'pid' => $process->pid, 'process_started' => $process->started,
            'head_before' => $process->head, 'tree_before' => $process->tree,
        ]);
        if ($updated === 0 && $claim->refresh()->status !== TaskCheckStatus::Running) {
            $this->checks->cancel($instance, $process);
        }
    }

    /** @return array{start: string|null, commands: list<array{id: string, command: string, directory: string, fails_on_base?: bool, paths?: list<string>}>} */
    private function reservedPayload(TaskCheck $claim): array
    {
        $stored = $claim->deliverable_evidence ?? [];
        $commands = $stored['commands'] ?? null;
        if (! is_array($commands) || count($commands) !== 1 || ! is_array($commands[0] ?? null)) {
            throw new TaskCheckException('The reserved probe payload is invalid.');
        }
        $raw = $commands[0];
        foreach (['id', 'command', 'directory'] as $field) {
            if (! is_string($raw[$field] ?? null)) {
                throw new TaskCheckException('The reserved probe command is invalid.');
            }
        }
        $deliverable = TaskDeliverable::fromArray($raw);
        $command = ['id' => $deliverable->id, 'command' => $deliverable->command, 'directory' => $deliverable->directory];
        if (array_key_exists('fails_on_base', $raw)) {
            $command['fails_on_base'] = $deliverable->fails_on_base;
        }
        if (array_key_exists('paths', $raw)) {
            $command['paths'] = $deliverable->paths;
        }

        return ['start' => is_string($stored['start'] ?? null) ? $stored['start'] : null, 'commands' => [$command]];
    }

    private function refuse(string $code, string $message, int $status): never
    {
        throw new ResourceOperationException(errorCode: 'tasks.probe_'.$code, message: $message, status: $status);
    }
}
