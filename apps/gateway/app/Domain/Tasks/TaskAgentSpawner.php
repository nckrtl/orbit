<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\AgentThread;
use App\Models\Instance;
use App\Models\Task;
use App\Models\TaskComment;
use Illuminate\Support\Facades\Config;
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
        private TaskTurnFetchNotice $fetchNotice = new TaskTurnFetchNotice,
    ) {}

    public function spawnReviewer(Task $task): ?int
    {
        return TaskExecutionHold::run($task->parent, fn (): ?int => $this->spawnAdmittedReviewer($task));
    }

    private function spawnAdmittedReviewer(Task $task): ?int
    {
        $existing = $this->subtaskReviewer($task);
        if ($existing !== null) {
            return $existing->id;
        }
        $pending = $this->pending($task->requireGroupId(), $task->id, TaskThreadRole::Reviewer);
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

    /** Sends an implementer's question to this subtask's reviewer, starting that reviewer when none exists. */
    public function consult(Task $task, string $message, ?string $key = null): ?int
    {
        return $this->sendReviewer($task, $message, new TaskTurnMode(consult: true), $key);
    }

    /** Sends a direction resolution to this subtask's reviewer, starting that reviewer when none exists. */
    public function relay(Task $task, string $message): ?int
    {
        return $this->sendReviewer($task, $message, new TaskTurnMode(relay: true));
    }

    /** Sends one reviewer turn, starting that reviewer when the subtask has none yet. */
    private function sendReviewer(Task $task, string $message, TaskTurnMode $mode, ?string $key = null): ?int
    {
        $existing = $this->subtaskReviewer($task);
        if ($existing instanceof AgentThread) {
            $this->drivers->get($existing->driver)->send($existing, $this->fetchNotice->apply($message), $key);

            return $existing->id;
        }

        $pending = $this->pending($task->requireGroupId(), $task->id, TaskThreadRole::Reviewer);
        if ($pending instanceof AgentThread) {
            if (! $this->installReviewerMcp($task)) {
                return null;
            }
            $this->installReceipt($pending, $mode);

            return $this->startPending($pending, $this->reviewTitle($task), $message, $key);
        }

        if (! $this->installReviewerMcp($task)) {
            return null;
        }
        $thread = $this->insertPending($task->parent, $task->id, TaskThreadRole::Reviewer);
        if (! $thread instanceof AgentThread) {
            return null;
        }
        $this->installReceipt($thread, $mode);

        return $this->startPending($thread, $this->reviewTitle($task), $message, $key);
    }

    /** Reserves the subtask reviewer's Orbit id before the opening prompt, or returns the thread that already exists. */
    public function reserveReviewer(Task $task): ?int
    {
        return TaskExecutionHold::run($task->parent, fn (): ?int => $this->reserveAdmittedReviewer($task));
    }

    private function reserveAdmittedReviewer(Task $task): ?int
    {
        $existing = $this->subtaskReviewer($task) ?? $this->pending($task->requireGroupId(), $task->id, TaskThreadRole::Reviewer);
        if ($existing instanceof AgentThread) {
            return $existing->id;
        }

        return $this->insertPending($task->parent, $task->id, TaskThreadRole::Reviewer)?->id;
    }

    /** Reserves the implementer's Orbit id before the opening prompt, or returns the thread that already exists. */
    public function reserveImplementer(Task $task): ?int
    {
        return TaskExecutionHold::run($task->parent, fn (): ?int => $this->reserveAdmittedImplementer($task));
    }

    private function reserveAdmittedImplementer(Task $task): ?int
    {
        if ($task->implementer_agent_thread_id !== null) {
            return (int) $task->implementer_agent_thread_id;
        }
        $existing = $this->pending($task->requireGroupId(), $task->id, TaskThreadRole::Implementer);
        if ($existing instanceof AgentThread) {
            return $existing->id;
        }

        return $this->insertPending($task->parent, $task->id, TaskThreadRole::Implementer)?->id;
    }

    public function spawnImplementer(Task $task): ?int
    {
        return TaskExecutionHold::run($task->parent, fn (): ?int => $this->spawnAdmittedImplementer($task));
    }

    private function spawnAdmittedImplementer(Task $task): ?int
    {
        if ($task->implementer_agent_thread_id !== null) {
            return (int) $task->implementer_agent_thread_id;
        }
        $group = $task->parent;
        $pending = $this->pending($group->id, $task->id, TaskThreadRole::Implementer);
        $needsReceipt = $pending === null || str_starts_with($task->fixup_problem ?? '', 'review:');
        $title = 'Orbit task #'.$group->id.' / subtask #'.$task->id.' · Implementer: '.$task->title;
        if ($pending === null) {
            $pending = $this->insertPending($group, $task->id, TaskThreadRole::Implementer);
            if ($pending === null) {
                return null;
            }
        }
        // A reserved row does not prove that context was installed. Refresh it
        // before every feedback startup, including retries after interruption.
        if ($needsReceipt) {
            $this->installReceipt($pending);
        }

        return $this->startPending($pending, $title, $this->implementerPrompt($group, $task, $pending->id));
    }

    public function requestReview(Task $task): void
    {
        TaskExecutionHold::run($task->parent, fn () => $this->requestAdmittedReview($task));
    }

    private function requestAdmittedReview(Task $task): void
    {
        $thread = $this->subtaskReviewer($task);
        if ($thread === null) {
            throw new AgentDriverException('Reviewer conversation is unavailable.');
        }
        try {
            $this->drivers->get($thread->driver)->send($thread, $this->fetchNotice->apply($this->reviewPacket($task, true, $thread->id)));
        } catch (AgentDriverException) {
            // ADR 0169: a continued thread that cannot take a turn is replaced by a fresh thread and a full packet.
            $replacement = $this->openReviewer($task);
            if ($replacement === null) {
                throw new AgentDriverException('The reviewer conversation could not be started.');
            }
            $task->parent->update(['reviewer_agent_thread_id' => $replacement]);
        }
    }

    /** The reviewer thread for this subtask, preferring the one the group currently points at. */
    private function subtaskReviewer(Task $task): ?AgentThread
    {
        $query = AgentThread::query()
            ->where('task_group_id', $task->parent_id)
            ->where('task_id', $task->id)
            ->where('role', TaskThreadRole::Reviewer->value)
            ->where('external_id', 'not like', self::PendingPrefix.'%');
        $pointed = Task::topLevel()->whereKey($task->parent_id)->value('reviewer_agent_thread_id');
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
        $thread = $this->insertPending($task->parent, $task->id, TaskThreadRole::Reviewer);
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
        $group = $task->parent;
        $instance = $group->taskable;
        if (! $instance instanceof Instance) {
            return true;
        }
        if ($this->mcp->installWhenMissing($instance)) {
            return true;
        }
        Log::error('The reviewer MCP file could not be written.', [
            'task_group_id' => $group->id,
            'instance_id' => $instance->id,
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

    private function insertPending(Task $group, ?int $taskId, TaskThreadRole $role): ?AgentThread
    {
        $group->loadMissing('taskable');
        $instance = $group->taskable;
        if (! $instance instanceof Instance) {
            return null;
        }
        $reviewer = $role === TaskThreadRole::Reviewer;

        return AgentThread::query()->create([
            'task_group_id' => $group->id,
            'task_id' => $taskId,
            'node_id' => $instance->node_id,
            'driver' => $reviewer ? $group->reviewer_agent_driver : $group->implementer_agent_driver,
            'runtime_key' => $instance->task_sandbox_id === null ? 'node:'.$instance->node_id : 'sandbox:'.$instance->task_sandbox_id,
            'external_id' => self::PendingPrefix.(string) Str::uuid(),
            'role' => $role->value,
            'model' => $reviewer ? $group->reviewer_model : $group->implementer_model,
            'effort' => config($reviewer ? 'orbit.tasks.reviewer_effort' : 'orbit.tasks.implementer_effort'),
        ]);
    }

    private function prepareReceipt(AgentThread $thread, ?TaskTurnMode $mode = null): void
    {
        $group = Task::topLevel()->with(['taskable', 'project'])->find($thread->task_group_id);
        $instance = $group?->taskable;
        $role = TaskThreadRole::tryFrom((string) $thread->role);
        if (! $group instanceof Task || ! $instance instanceof Instance || $role === null) {
            return;
        }
        $task = is_numeric($thread->task_id) ? Task::query()->find((int) $thread->task_id) : null;
        $needsContext = $task instanceof Task && ($role === TaskThreadRole::Reviewer
            || str_starts_with($task->fixup_problem ?? '', 'review:'));
        $context = $needsContext ? $this->packets->reviewContext($task) : null;
        app(TaskTurnReceipts::class)->prepare(
            $instance,
            $role,
            $role === TaskThreadRole::Reviewer && $task instanceof Task && $task->opensPullRequest(),
            $task instanceof Task ? $task->deliverableList() : [],
            $thread->id,
            $mode ?? $this->reviewerTurnMode($task, $role),
            $context,
        );
    }

    /** A relay or a review after a direction resolution keeps that mode when the thread is replaced. */
    private function reviewerTurnMode(?Task $task, TaskThreadRole $role): ?TaskTurnMode
    {
        if ($role !== TaskThreadRole::Reviewer || ! $task instanceof Task) {
            return null;
        }
        if ($task->consult_comment_id !== null) {
            return new TaskTurnMode(consult: true);
        }
        if ($task->direction_relay_comment_id !== null) {
            return new TaskTurnMode(relay: true);
        }
        if (TaskQuestions::awaitsCause($task)) {
            return new TaskTurnMode(causeRequired: true);
        }

        return null;
    }

    /** Installs the turn file and full task context, and removes the reserved row when that install fails so the replacement does not start. */
    private function installReceipt(AgentThread $thread, ?TaskTurnMode $mode = null): void
    {
        try {
            $this->prepareReceipt($thread, $mode);
        } catch (Throwable $exception) {
            if ($thread->exists) {
                $thread->delete();
            }

            throw $exception;
        }
    }

    private function startPending(AgentThread $thread, string $title, string $prompt, ?string $key = null): ?int
    {
        $prompt = $this->fetchNotice->apply($prompt);
        $group = Task::topLevel()->with('taskable')->find($thread->task_group_id);
        $instance = $group?->taskable;
        if (! $group instanceof Task || ! $instance instanceof Instance
            || $thread->runtime_key !== ($instance->task_sandbox_id === null ? 'node:'.$instance->node_id : 'sandbox:'.$instance->task_sandbox_id)) {
            $thread->delete();

            return null;
        }
        $instance->loadMissing('node');
        $role = TaskThreadRole::tryFrom((string) $thread->role);
        if ($role === null) {
            $thread->delete();

            return null;
        }
        $driver = $this->drivers->get($thread->driver);
        $reserved = is_string($key) && $key !== '' ? $this->openingIdentity($thread) : null;
        try {
            $externalId = $driver->create(new AgentThreadStart(
                $instance->node, $instance, $title, $prompt,
                $thread->model ?? '', $thread->effort ?? Config::string($role === TaskThreadRole::Reviewer ? 'orbit.tasks.reviewer_effort' : 'orbit.tasks.implementer_effort'), $role,
                $key,
                $reserved,
                $reserved !== null,
            ));
        } catch (AgentDriverException $exception) {
            $created = $exception->createdThreadId;
            if (is_string($created) && $created !== '') {
                $thread->update(['external_id' => $created]);

                throw $exception;
            }
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
        if ($reserved !== null) {
            // The id is durable before the opening turn. A lost response reconnects to this
            // conversation instead of creating another one.
            $this->rememberExternalId($thread, $externalId);
            $thread->refresh();
            try {
                $driver->send($thread, $prompt, $key);
            } catch (AgentDriverException $exception) {
                $exception->createdThreadId = $externalId;

                throw $exception;
            }
            $this->rememberExternalId($thread, $externalId);

            return $thread->id;
        }
        $thread->update(['external_id' => $externalId]);

        return $thread->id;
    }

    /** The same local row always names the same remote conversation. */
    private function openingIdentity(AgentThread $thread): string
    {
        $hash = md5('orbit-agent-thread:'.$thread->id);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 12, 4),
            substr($hash, 16, 4),
            substr($hash, 20, 12),
        );
    }

    private function rememberExternalId(AgentThread $thread, string $externalId): void
    {
        AgentThread::query()->whereKey($thread->id)->update([
            'external_id' => $externalId,
            'updated_at' => now(),
        ]);
        $thread->external_id = $externalId;
    }

    private function reviewTitle(Task $task): string
    {
        return 'Orbit task #'.$task->parent_id.' · Review: '.$task->title;
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

    private function implementerPrompt(Task $group, Task $task, ?int $threadId = null): string
    {
        return TaskPromptRenderer::implementer(
            new TaskPromptGroup(
                id: $group->id,
                title: $group->title,
                brief: $group->brief,
                projectSlug: $group->project->slug,
                projectId: $group->project_id,
                defaultBranch: is_string($group->project->default_branch) ? $group->project->default_branch : null,
                taskCheck: $group->project->taskCheckCommand(),
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
