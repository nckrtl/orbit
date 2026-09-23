<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\AgentThread;
use App\Models\AppInstance;
use App\Models\Task;
use App\Models\TaskGroup;
use Illuminate\Support\Facades\Log;

final readonly class TaskAgentSpawner implements AgentSpawner
{
    public function __construct(private AgentDriverRegistry $drivers, private TaskWorkspaceSigner $signer) {}

    public function spawnReviewer(TaskGroup $group): ?int
    {
        if ($group->reviewer_agent_thread_id !== null) {
            return $group->reviewer_agent_thread_id;
        }

        return $this->spawn($group, null, TaskThreadRole::Reviewer, 'Orbit task #'.$group->id.' · Reviewer: '.$group->title, $this->reviewerPrompt($group));
    }

    public function spawnImplementer(Task $task): ?int
    {
        if ($task->implementer_agent_thread_id !== null) {
            return $task->implementer_agent_thread_id;
        }
        $group = $task->taskGroup;

        return $this->spawn($group, $task->id, TaskThreadRole::Implementer, 'Orbit task #'.$group->id.' / subtask #'.$task->id.' · Implementer: '.$task->title, $this->implementerPrompt($group, $task));
    }

    private function spawn(TaskGroup $group, ?int $taskId, TaskThreadRole $role, string $title, string $prompt): ?int
    {
        $instance = $group->taskable;
        if (! $instance instanceof AppInstance) {
            return null;
        }
        $reviewer = $role === TaskThreadRole::Reviewer;
        $model = $reviewer ? $group->reviewer_model : $group->implementer_model;
        $effort = $reviewer ? TaskAgentDefaults::ReviewerEffort : TaskAgentDefaults::ImplementerEffort;
        try {
            $driver = $this->drivers->get($reviewer ? $group->reviewer_agent_driver : $group->implementer_agent_driver);
            $externalId = $driver->create(new AgentThreadStart($instance->node, $instance, $title, $prompt, $model, $effort, $role));
        } catch (AgentDriverException) {
            Log::error('Agent conversation creation failed.', ['task_group_id' => $group->id, 'task_id' => $taskId, 'role' => $role->value]);

            return null;
        }
        if ($externalId === '') {
            return null;
        }
        $thread = AgentThread::query()->firstOrCreate([
            'driver' => $driver->key(), 'runtime_key' => 'node:'.$instance->node_id, 'external_id' => $externalId,
        ], [
            'task_group_id' => $group->id, 'task_id' => $taskId, 'node_id' => $instance->node_id,
            'role' => $role->value, 'model' => $model, 'effort' => $effort,
        ]);
        if ($thread->task_group_id !== $group->id || $thread->task_id !== $taskId || $thread->role !== $role->value) {
            throw new AgentDriverException('Agent conversation ownership does not match.');
        }

        return $thread->id;
    }

    public function requestReview(Task $task): void
    {
        $thread = $task->taskGroup->reviewerThread;
        if ($thread === null) {
            throw new AgentDriverException('Reviewer conversation is unavailable.');
        }
        $this->drivers->get($thread->driver)->send($thread, $this->reviewPrompt($task));
    }

    public function signOff(Task $task): ?string
    {
        $instance = $task->taskGroup->taskable;

        return $instance instanceof AppInstance ? $this->signer->commit($instance, 'Reviewer sign-off: '.$task->title) : null;
    }

    private function reviewerPrompt(TaskGroup $group): string
    {
        return implode("\n\n", [
            'You are the long-lived reviewer for this feature group.',
            'Orbit task group #'.$group->id,
            'Feature: '.$group->title,
            $group->brief,
            $this->contract($group).' Review each subtask against them.',
            'Wait for subtask review handoffs. After you accept a subtask, create the sign-off commit in this workspace.',
        ]);
    }

    private function implementerPrompt(TaskGroup $group, Task $task): string
    {
        return implode("\n\n", [
            'Implement this subtask in the shared workspace, then stop so the reviewer can inspect it.',
            'Orbit task group #'.$group->id,
            'Feature: '.$group->title,
            'Orbit subtask #'.$task->id,
            'Subtask: '.$task->title,
            $task->brief,
            $this->contract($group).' Build to them.',
        ]);
    }

    /** ADR 0122: the ADRs and documentation prepared on the branch while the group was in Backlog. */
    private function contract(TaskGroup $group): string
    {
        $branch = $group->app->default_branch;
        $base = is_string($branch) && $branch !== '' ? '`origin/'.$branch.'`' : 'the Project default branch';

        return 'The ADRs and documentation that this branch changes against '.$base.' are the feature\'s contract.';
    }

    private function reviewPrompt(Task $task): string
    {
        return implode("\n\n", [
            'please review',
            'Orbit task group #'.$task->task_group_id.' / subtask #'.$task->id,
            'Subtask '.$task->title.' is done.',
            $task->brief,
        ]);
    }
}
