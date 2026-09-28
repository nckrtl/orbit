<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\AgentThread;
use App\Models\AppInstance;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TaskGroup;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final readonly class TaskAgentSpawner implements AgentSpawner
{
    public const string PendingPrefix = 'pending:';

    public function __construct(
        private AgentDriverRegistry $drivers,
        private TaskReviewPacketBuilder $packets,
        private TaskWorkspaceMcp $mcp,
    ) {}

    public function spawnReviewer(Task $task): ?int
    {
        $existing = $this->subtaskReviewer($task);
        if ($existing !== null) {
            return $existing->id;
        }
        $pending = $this->pending($task->task_group_id, $task->id, TaskThreadRole::Reviewer);
        if ($pending !== null) {
            if (! $this->installReviewerMcp($task)) {
                return null;
            }
            $started = $this->startPending($pending, $this->reviewTitle($task), $this->reviewPacket($task, false, $pending->id));
            if ($started !== null) {
                $this->markHeldResolutionDelivered($task);
            }

            return $started;
        }

        return $this->openReviewer($task);
    }

    /** Reserves the subtask reviewer's Orbit id before the opening prompt, or returns the thread that already exists. */
    public function reserveReviewer(Task $task): ?int
    {
        $existing = $this->subtaskReviewer($task) ?? $this->pending($task->task_group_id, $task->id, TaskThreadRole::Reviewer);
        if ($existing instanceof AgentThread) {
            return $existing->id;
        }

        return $this->insertPending($task->taskGroup, $task->id, TaskThreadRole::Reviewer)?->id;
    }

    /** Reserves the implementer's Orbit id before the opening prompt, or returns the thread that already exists. */
    public function reserveImplementer(Task $task): ?int
    {
        if ($task->implementer_agent_thread_id !== null) {
            return (int) $task->implementer_agent_thread_id;
        }
        $existing = $this->pending($task->task_group_id, $task->id, TaskThreadRole::Implementer);
        if ($existing instanceof AgentThread) {
            return $existing->id;
        }

        return $this->insertPending($task->taskGroup, $task->id, TaskThreadRole::Implementer)?->id;
    }

    public function spawnImplementer(Task $task): ?int
    {
        if ($task->implementer_agent_thread_id !== null) {
            return (int) $task->implementer_agent_thread_id;
        }
        $group = $task->taskGroup;
        $pending = $this->pending($group->id, $task->id, TaskThreadRole::Implementer);
        $title = 'Orbit task #'.$group->id.' / subtask #'.$task->id.' · Implementer: '.$task->title;
        if ($pending === null) {
            $pending = $this->insertPending($group, $task->id, TaskThreadRole::Implementer);
            if ($pending === null) {
                return null;
            }
            $this->installReceipt($pending);
        }

        return $this->startPending($pending, $title, $this->implementerPrompt($group, $task, $pending->id));
    }

    public function requestReview(Task $task): void
    {
        $thread = $this->subtaskReviewer($task);
        if ($thread === null) {
            throw new AgentDriverException('Reviewer conversation is unavailable.');
        }
        try {
            $this->drivers->get($thread->driver)->send($thread, $this->reviewPacket($task, true, $thread->id));
        } catch (AgentDriverException) {
            // ADR 0169: a continued thread that cannot take a turn is replaced by a fresh thread and a full packet.
            $replacement = $this->openReviewer($task);
            if ($replacement === null) {
                throw new AgentDriverException('The reviewer conversation could not be started.');
            }
            $task->taskGroup->update(['reviewer_agent_thread_id' => $replacement]);
        }
    }

    /** The reviewer thread for this subtask, preferring the one the group currently points at. */
    private function subtaskReviewer(Task $task): ?AgentThread
    {
        $query = AgentThread::query()
            ->where('task_group_id', $task->task_group_id)
            ->where('task_id', $task->id)
            ->where('role', TaskThreadRole::Reviewer->value)
            ->where('external_id', 'not like', self::PendingPrefix.'%');
        $pointed = TaskGroup::query()->whereKey($task->task_group_id)->value('reviewer_agent_thread_id');
        if (is_numeric($pointed)) {
            $match = (clone $query)->whereKey((int) $pointed)->first();
            if ($match instanceof AgentThread) {
                return $match;
            }
        }

        return $query->orderByDesc('id')->first();
    }

    private function openReviewer(Task $task): ?int
    {
        if (! $this->installReviewerMcp($task)) {
            return null;
        }
        $thread = $this->insertPending($task->taskGroup, $task->id, TaskThreadRole::Reviewer);
        if ($thread === null) {
            return null;
        }
        $this->installReceipt($thread);
        $started = $this->startPending($thread, $this->reviewTitle($task), $this->reviewPacket($task, false, $thread->id));
        if ($started !== null) {
            $this->markHeldResolutionDelivered($task);
        }

        return $started;
    }

    private function installReviewerMcp(Task $task): bool
    {
        $group = $task->taskGroup;
        $instance = $group->taskable;
        if (! $instance instanceof AppInstance) {
            return true;
        }
        if ($this->mcp->installWhenMissing($instance)) {
            return true;
        }
        Log::error('The reviewer MCP file could not be written.', [
            'task_group_id' => $group->id,
            'app_instance_id' => $instance->id,
        ]);

        return false;
    }

    private function pending(int $groupId, ?int $taskId, TaskThreadRole $role): ?AgentThread
    {
        $query = AgentThread::query()
            ->where('task_group_id', $groupId)
            ->where('role', $role->value)
            ->where('external_id', 'like', self::PendingPrefix.'%');
        $taskId === null ? $query->whereNull('task_id') : $query->where('task_id', $taskId);

        $thread = $query->orderByDesc('id')->first();

        return $thread instanceof AgentThread ? $thread : null;
    }

    private function insertPending(TaskGroup $group, ?int $taskId, TaskThreadRole $role): ?AgentThread
    {
        $group->loadMissing('taskable');
        $instance = $group->taskable;
        if (! $instance instanceof AppInstance) {
            return null;
        }
        $reviewer = $role === TaskThreadRole::Reviewer;

        return AgentThread::query()->create([
            'task_group_id' => $group->id,
            'task_id' => $taskId,
            'node_id' => $instance->node_id,
            'driver' => $reviewer ? $group->reviewer_agent_driver : $group->implementer_agent_driver,
            'runtime_key' => 'node:'.$instance->node_id,
            'external_id' => self::PendingPrefix.(string) Str::uuid(),
            'role' => $role->value,
            'model' => $reviewer ? $group->reviewer_model : $group->implementer_model,
            'effort' => $reviewer ? TaskAgentDefaults::ReviewerEffort : TaskAgentDefaults::ImplementerEffort,
        ]);
    }

    private function prepareReceipt(AgentThread $thread): void
    {
        $group = TaskGroup::query()->with(['taskable', 'app'])->find($thread->task_group_id);
        $instance = $group?->taskable;
        $role = TaskThreadRole::tryFrom((string) $thread->role);
        if (! $group instanceof TaskGroup || ! $instance instanceof AppInstance || $role === null) {
            return;
        }
        $task = is_numeric($thread->task_id) ? Task::query()->find((int) $thread->task_id) : null;
        app(TaskRunReceipts::class)->prepare(
            $instance,
            $role,
            $role === TaskThreadRole::Reviewer && $task instanceof Task && $task->opensPullRequest(),
            $task instanceof Task ? $task->deliverableList() : [],
            $thread->id,
        );
    }

    /** Installs the turn file, and removes the reserved row when that install fails so the replacement does not start. */
    private function installReceipt(AgentThread $thread): void
    {
        try {
            $this->prepareReceipt($thread);
        } catch (Throwable $exception) {
            if ($thread->exists) {
                $thread->delete();
            }

            throw $exception;
        }
    }

    private function startPending(AgentThread $thread, string $title, string $prompt): ?int
    {
        $group = TaskGroup::query()->with('taskable')->find($thread->task_group_id);
        $instance = $group?->taskable;
        if (! $group instanceof TaskGroup || ! $instance instanceof AppInstance) {
            $thread->delete();

            return null;
        }
        $instance->loadMissing('node');
        $role = TaskThreadRole::tryFrom((string) $thread->role);
        if ($role === null) {
            $thread->delete();

            return null;
        }
        try {
            $driver = $this->drivers->get($thread->driver);
            $externalId = $driver->create(new AgentThreadStart(
                $instance->node, $instance, $title, $prompt,
                $thread->model ?? '', $thread->effort ?? '', $role,
            ));
        } catch (AgentDriverException) {
            Log::error('Agent conversation creation failed.', ['task_group_id' => $group->id, 'task_id' => $thread->task_id, 'role' => $thread->role]);
            $thread->delete();

            return null;
        } catch (Throwable $exception) {
            if ($thread->exists) {
                $thread->delete();
            }

            throw $exception;
        }
        if ($externalId === '') {
            $thread->delete();

            return null;
        }
        $conflict = AgentThread::query()
            ->where('driver', $thread->driver)
            ->where('runtime_key', $thread->runtime_key)
            ->where('external_id', $externalId)
            ->whereKeyNot($thread->id)
            ->first();
        if ($conflict instanceof AgentThread) {
            $thread->delete();
            if ($conflict->task_group_id !== $group->id || $conflict->task_id !== $thread->task_id || $conflict->role !== $thread->role) {
                throw new AgentDriverException('Agent conversation ownership does not match.');
            }

            return $conflict->id;
        }
        $thread->update(['external_id' => $externalId]);

        return $thread->id;
    }

    private function reviewTitle(Task $task): string
    {
        return 'Orbit task #'.$task->task_group_id.' · Review: '.$task->title;
    }

    /** The opening packet carried this attempt's held resolution, so record that delivery. */
    private function markHeldResolutionDelivered(Task $task): void
    {
        $commentId = TaskComment::query()
            ->where('task_id', $task->id)
            ->where('type', TaskCommentType::Resolution->value)
            ->where('review_attempt', $task->review_attempt)
            ->latest('id')
            ->value('id');
        if (! is_numeric($commentId)) {
            return;
        }
        Task::query()->whereKey($task->id)->update(['resolution_delivered_comment_id' => (int) $commentId]);
    }

    private function reviewPacket(Task $task, bool $continued, ?int $threadId = null): string
    {
        return $this->packets->build($task, $continued, $threadId);
    }

    private function implementerPrompt(TaskGroup $group, Task $task, ?int $threadId = null): string
    {
        return TaskPromptRenderer::implementer(
            new TaskPromptGroup(
                id: $group->id,
                title: $group->title,
                brief: $group->brief,
                projectSlug: $group->app->slug,
                projectId: $group->app_id,
                defaultBranch: is_string($group->app->default_branch) ? $group->app->default_branch : null,
                taskCheck: $group->app->taskCheckCommand(),
                startCommit: TaskReviewBase::groupStartCommit($group),
            ),
            new TaskPromptSubtask(
                id: $task->id,
                title: $task->title,
                brief: $task->brief,
                position: $task->position,
                deliverables: $task->deliverableList(),
            ),
            $threadId,
        );
    }
}
