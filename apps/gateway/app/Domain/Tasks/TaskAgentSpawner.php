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

final readonly class TaskAgentSpawner implements AgentSpawner, TaskPlannerSpawner
{
    public const string PendingPrefix = 'pending:';

    public function __construct(
        private AgentDriverRegistry $drivers,
        private TaskReviewPacketBuilder $packets,
        private TaskPlannerMcp $mcp,
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

    public function spawnPlanner(TaskGroup $group): ?int
    {
        if ($group->reviewer_agent_thread_id !== null) {
            return $group->reviewer_agent_thread_id;
        }

        return $this->spawn($group, null, TaskThreadRole::Reviewer, 'Orbit task #'.$group->id.' · Planner: '.$group->title, $this->plannerPrompt($group));
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

    private function spawn(TaskGroup $group, ?int $taskId, TaskThreadRole $role, string $title, string $prompt): ?int
    {
        $thread = $this->insertPending($group, $taskId, $role);
        if ($thread === null) {
            return null;
        }
        if ($taskId !== null) {
            $this->installReceipt($thread);
        }

        return $this->startPending($thread, $title, $prompt);
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
        } catch (TaskRunReceiptException $exception) {
            $thread->delete();

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

    private function plannerPrompt(TaskGroup $group): string
    {
        return implode("\n\n", [
            'You are the planner for this Orbit task group. Shape the feature with the operator in this thread before any agent implements it.',
            'Orbit task group #'.$group->id.' for Project '.$group->app->slug.' (app_id '.$group->app_id.')',
            'Feature: '.$group->title,
            $group->brief,
            'Follow this repository\'s instructions for designing a feature. Write the ADRs and documentation in this workspace on the branch task-'.$group->id.' and leave them uncommitted. Orbit commits them when the group moves to Todo.',
            'Keep the group current through Orbit MCP: tasks-update for the title and brief, and tasks-subtask-create, tasks-subtask-update, and tasks-subtask-destroy for the subtasks. Split the feature with the creating-tasks skill (.agents/skills/creating-tasks/SKILL.md): each subtask has one concise goal that an implementer finishes and a reviewer verifies in one turn, and its brief names the ADR sections and documentation it implements.',
            'Give every subtask at least one deliverable and at most five in its deliverables list, and turn each explicit item of its brief into one. A subtask that needs more than five is too large: split it. A deliverable has an id (a lowercase slug, unique in the subtask), a type, and a description. Use type file with path and change (created, modified, or any) for a file the step must create or change; type test with project, file, and name for a Pest test it must add or change and that must pass, and set fails_on_base to true when at least one test whose name contains name must fail on the start commit before every such test passes; type command with command and directory for a check in another ecosystem that must exit 0; and type review for an item only the reviewer can judge. Orbit verifies file, test, and command deliverables before each review, and refuses to move the group to Todo while a subtask has none.',
            'When the operator agrees the plan is ready, move the group to Todo with tasks-update and status todo. Orbit then runs the implementers. This thread stays the planner. Each subtask review starts a fresh reviewer thread.',
        ]);
    }

    private function implementerPrompt(TaskGroup $group, Task $task, ?int $threadId = null): string
    {
        $deliverables = $task->deliverableList();

        return implode("\n\n", array_filter([
            'Implement this subtask in the shared workspace. '.TaskRunInstructions::implementer($deliverables, $group->app->taskCheckCommand(), $threadId),
            'Orbit task group #'.$group->id,
            'Feature: '.$group->title,
            'Orbit subtask #'.$task->id,
            'Subtask: '.$task->title,
            $task->brief,
            TaskRunInstructions::deliverables($deliverables),
            TaskRunInstructions::contract(is_string($group->app->default_branch) ? $group->app->default_branch : null).' Build to them.',
        ], static fn (string $part): bool => $part !== ''));
    }
}
