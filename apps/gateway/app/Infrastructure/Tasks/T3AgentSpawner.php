<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\Tasks\AgentSpawner;
use App\Domain\Tasks\T3Dispatcher;
use App\Domain\Tasks\T3DispatchException;
use App\Domain\Tasks\TaskAgentDefaults;
use App\Domain\Tasks\TaskWorkspaceSigner;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Task;
use App\Models\TaskGroup;
use Illuminate\Support\Str;

final readonly class T3AgentSpawner implements AgentSpawner
{
    public function __construct(
        private T3Dispatcher $dispatcher,
        private TaskWorkspaceSigner $signer,
    ) {}

    public function spawnReviewer(TaskGroup $group): ?string
    {
        $group->loadMissing(['app', 'taskable']);

        if (is_string($group->reviewer_thread_id) && $group->reviewer_thread_id !== '') {
            return $group->reviewer_thread_id;
        }

        return $this->spawnThread(
            $group,
            title: 'Reviewer: '.$group->title,
            model: $group->reviewer_model !== '' ? $group->reviewer_model : TaskAgentDefaults::ReviewerModel,
            effort: TaskAgentDefaults::ReviewerEffort,
            message: $this->reviewerPrompt($group),
        );
    }

    public function spawnImplementer(Task $task): ?string
    {
        $task->loadMissing('taskGroup.app', 'taskGroup.taskable');

        if (is_string($task->implementer_thread_id) && $task->implementer_thread_id !== '') {
            return $task->implementer_thread_id;
        }

        $group = $task->taskGroup;

        return $this->spawnThread(
            $group,
            title: 'Implementer: '.$task->title,
            model: $group->implementer_model !== '' ? $group->implementer_model : TaskAgentDefaults::ImplementerModel,
            effort: TaskAgentDefaults::ImplementerEffort,
            message: $this->implementerPrompt($group, $task),
        );
    }

    public function requestReview(Task $task): void
    {
        $task->loadMissing('taskGroup.app', 'taskGroup.taskable');
        $group = $task->taskGroup;
        $node = $this->node($group);

        if (! $node instanceof Node || ! is_string($group->reviewer_thread_id) || $group->reviewer_thread_id === '') {
            return;
        }

        try {
            $this->startTurn($node, $group->reviewer_thread_id, $this->reviewPrompt($task));
        } catch (T3DispatchException) {
        }
    }

    public function signOff(Task $task): ?string
    {
        $task->loadMissing('taskGroup.taskable');
        $instance = $task->taskGroup->taskable;

        if (! $instance instanceof AppInstance) {
            return null;
        }

        return $this->signer->commit(
            $instance,
            'Reviewer sign-off: '.$task->title,
        );
    }

    private function spawnThread(
        TaskGroup $group,
        string $title,
        string $model,
        string $effort,
        string $message,
    ): ?string {
        $node = $this->node($group);
        $instance = $group->taskable;

        if (! $node instanceof Node || ! $instance instanceof AppInstance) {
            return null;
        }

        $threadId = (string) Str::uuid();
        $projectId = (string) Str::uuid();
        $createdAt = now()->toIso8601String();
        $selection = [
            'instanceId' => $model,
            'model' => $model,
            'options' => [['effort' => $effort]],
        ];

        try {
            $this->dispatcher->dispatch($node, [
                'type' => 'project.create',
                'commandId' => (string) Str::uuid(),
                'projectId' => $projectId,
                'workspaceRoot' => $instance->checkout_path,
                'title' => $group->title,
                'defaultModelSelection' => $selection,
                'createdAt' => $createdAt,
            ]);
            $created = $this->dispatcher->dispatch($node, [
                'type' => 'thread.create',
                'commandId' => (string) Str::uuid(),
                'threadId' => $threadId,
                'projectId' => $projectId,
                'title' => $title,
                'modelSelection' => $selection,
                'runtimeMode' => 'full-access',
                'interactionMode' => 'default',
                'branch' => $instance->branch ?? $instance->name,
                'worktreePath' => $instance->checkout_path,
                'createdAt' => $createdAt,
            ]);
            $this->startTurn($node, $created['thread_id'], $message);

            return $created['thread_id'];
        } catch (T3DispatchException) {
            return null;
        }
    }

    private function startTurn(Node $node, string $threadId, string $message): void
    {
        $this->dispatcher->dispatch($node, [
            'type' => 'thread.turn.start',
            'commandId' => (string) Str::uuid(),
            'threadId' => $threadId,
            'messageId' => (string) Str::uuid(),
            'message' => $message,
            'createdAt' => now()->toIso8601String(),
        ]);
    }

    private function node(TaskGroup $group): ?Node
    {
        $instance = $group->taskable;

        if (! $instance instanceof AppInstance) {
            return null;
        }

        $instance->loadMissing('node');

        return $instance->node;
    }

    private function reviewerPrompt(TaskGroup $group): string
    {
        return implode("\n\n", [
            'You are the long-lived reviewer for this feature group.',
            'Feature: '.$group->title,
            $group->brief,
            'Wait for subtask review handoffs. After you accept a subtask, create the sign-off commit in this workspace.',
        ]);
    }

    private function implementerPrompt(TaskGroup $group, Task $task): string
    {
        return implode("\n\n", [
            'Implement this subtask in the shared workspace, then stop so the reviewer can inspect it.',
            'Feature: '.$group->title,
            'Subtask: '.$task->title,
            $task->brief,
        ]);
    }

    private function reviewPrompt(Task $task): string
    {
        return implode("\n\n", [
            'please review',
            'Subtask '.$task->title.' is done.',
            $task->brief,
        ]);
    }
}
