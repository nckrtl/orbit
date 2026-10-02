<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\AgentThreadState;
use App\Domain\Tasks\AssistanceKind;
use App\Domain\Tasks\CoderSettleNotifier;
use App\Domain\Tasks\TaskAgentSpawner;
use App\Domain\Tasks\TaskAssistance;
use App\Domain\Tasks\TaskExecutionHold;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskThreadRole;
use App\Models\Activity;
use App\Models\AgentThread;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** ADR 0192: hold the group and notify its acting threads without interrupting their turns. */
final readonly class RequestEndedPullRequestAssistanceAction
{
    public const string ReasonPrefix = 'Watched pull request ended: ';

    public function __construct(private AgentDriverRegistry $drivers, private CoderSettleNotifier $coder) {}

    public static function isReason(?string $reason): bool
    {
        return is_string($reason) && str_starts_with($reason, self::ReasonPrefix);
    }

    /** Returns whether this group must not advance, including while a pending notice is retried. */
    public function execute(Task $group): bool
    {
        return TaskExecutionHold::run($group, fn (): bool => $this->requestWhileAdmitted($group)) ?? true;
    }

    private function requestWhileAdmitted(Task $group): bool
    {
        $group->loadMissing('tasks');
        $open = $group->tasks
            ->filter(static fn (Task $task): bool => in_array($task->status, [TaskStatus::Todo, TaskStatus::Running, TaskStatus::Reviewing], true))
            ->sortBy(static fn (Task $task): array => [$task->position, $task->id]);
        if (! self::isReason($group->assistance_reason)) {
            if ($open->isEmpty() || ! in_array($group->watched_pr_state, ['merged', 'closed'], true) || ! is_string($group->watched_pr_url) || $group->watched_pr_url === '') {
                return false;
            }
            $list = $open->map(static fn (Task $task): string => '#'.$task->id.' '.$task->title)->implode(', ');
            $reason = self::ReasonPrefix.$group->watched_pr_url.' is '.$group->watched_pr_state.'. Open subtasks: '.$list.'.';
            DB::transaction(function () use ($group, $open, $reason): void {
                $group->update(TaskAssistance::attributes(AssistanceKind::Failure, null, $reason));
                foreach ($open as $task) {
                    if (in_array($task->status, [TaskStatus::Running, TaskStatus::Reviewing], true)) {
                        $task->update(TaskAssistance::attributes(AssistanceKind::Failure, null, $reason));
                    }
                }
            });
            $this->coder->assistance($group, $reason);
        }

        foreach ($open as $task) {
            if (in_array($task->status, [TaskStatus::Running, TaskStatus::Reviewing], true)) {
                $this->notice($group, $task);
            }
        }

        return true;
    }

    private function notice(Task $group, Task $task): void
    {
        // Persist before observing or sending. A process stop at either boundary leaves a retryable key.
        $reserved = DB::transaction(function () use ($group, $task): Task {
            $locked = Task::query()->lockForUpdate()->findOrFail($task->id);
            if ($locked->ended_pr_notice_thread_id === null) {
                $thread = $this->actingThread($group, $locked);
                if ($thread instanceof AgentThread) {
                    $locked->update([
                        'ended_pr_notice_thread_id' => $thread->id,
                        'ended_pr_notice_key' => (string) Str::uuid(),
                        'ended_pr_notice_state' => 'pending',
                    ]);
                }
            }

            return $locked;
        });
        if ($reserved->ended_pr_notice_state !== 'pending' || $reserved->ended_pr_notice_key === null) {
            return;
        }
        $thread = AgentThread::query()->where('task_group_id', $group->id)->find($reserved->ended_pr_notice_thread_id);
        if (! $thread instanceof AgentThread) {
            return;
        }
        try {
            $driver = $this->drivers->get($thread->driver);
            $observed = $driver->observe($thread);
            $stopped = in_array($observed->state, [AgentThreadState::Idle, AgentThreadState::Done, AgentThreadState::Failed], true)
                || ($observed->state === AgentThreadState::AskingForInput && $observed->inputRequests === []);
            if (! $stopped) {
                return;
            }
            $driver->send($thread, $group->assistance_reason ?? '', $reserved->ended_pr_notice_key);
        } catch (Throwable) {
            $this->log($reserved, 'ended pull request notice delivery failed');

            return;
        }
        // The remote send stays outside the transaction. Persist acceptance and its audit together:
        // a failure of either leaves the committed pending key available for an idempotent retry.
        DB::transaction(function () use ($reserved): void {
            $locked = Task::query()->lockForUpdate()->findOrFail($reserved->id);
            if ($locked->ended_pr_notice_state !== 'pending') {
                return;
            }
            $locked->update(['ended_pr_notice_state' => 'delivered']);
            $this->log($locked, 'ended pull request notice delivered');
        });
    }

    private function actingThread(Task $group, Task $task): ?AgentThread
    {
        if ($task->status === TaskStatus::Running) {
            $thread = $task->implementerThread;

            return $thread instanceof AgentThread && ! str_starts_with($thread->external_id, TaskAgentSpawner::PendingPrefix) ? $thread : null;
        }
        $reviewers = AgentThread::query()->where('task_group_id', $group->id)->where('task_id', $task->id)
            ->where('role', TaskThreadRole::Reviewer->value)
            ->where('external_id', 'not like', TaskAgentSpawner::PendingPrefix.'%');

        return (clone $reviewers)->whereKey($group->reviewer_agent_thread_id)->first() ?? $reviewers->orderByDesc('id')->first();
    }

    private function log(Task $task, string $description): void
    {
        Activity::query()->create([
            'log_name' => 'tasks', 'description' => $description, 'subject_type' => $task::class,
            'subject_id' => $task->id, 'properties' => ['thread_id' => $task->ended_pr_notice_thread_id, 'send_key' => $task->ended_pr_notice_key],
            'request_id' => (string) Str::uuid(), 'command' => 'tasks:tick', 'status' => 'completed',
        ]);
    }
}
