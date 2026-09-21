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
use App\Models\TaskAgentSession;
use App\Models\TaskGroup;
use Illuminate\Support\Facades\Log;
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
            title: 'Orbit task #'.$group->id.' · Reviewer: '.$group->title,
            selection: TaskAgentDefaults::reviewerSelection(
                $group->reviewer_model !== '' ? $group->reviewer_model : null,
            ),
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
            title: 'Orbit task #'.$group->id.' / subtask #'.$task->id.' · Implementer: '.$task->title,
            selection: TaskAgentDefaults::implementerSelection(
                $group->implementer_model !== '' ? $group->implementer_model : null,
            ),
            message: $this->implementerPrompt($group, $task),
            taskId: $task->id,
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
            $this->startTurn(
                $node,
                $group->reviewer_thread_id,
                $this->reviewPrompt($task),
                TaskAgentDefaults::reviewerSelection(
                    $group->reviewer_model !== '' ? $group->reviewer_model : null,
                ),
            );
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

    /**
     * @param  array{instanceId: string, model: string, options: list<array{id: string, value: string}>}  $selection
     */
    private function spawnThread(
        TaskGroup $group,
        string $title,
        array $selection,
        string $message,
        ?int $taskId = null,
    ): ?string {
        $node = $this->node($group);
        $instance = $group->taskable;

        if (! $node instanceof Node || ! $instance instanceof AppInstance) {
            return null;
        }

        $threadId = (string) Str::uuid();
        $projectId = (string) Str::uuid();
        $createdAt = now()->toIso8601String();

        try {
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
            } catch (T3DispatchException $exception) {
                if (! is_string($exception->existingProjectId)) {
                    return null;
                }

                $projectId = $exception->existingProjectId;
            }

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
        } catch (T3DispatchException) {
            return null;
        }

        $resolvedThreadId = $created['thread_id'] !== '' ? $created['thread_id'] : $threadId;

        try {
            $this->startOpeningTurn($node, $resolvedThreadId, $message, $selection);
        } catch (T3DispatchException) {
            TaskAgentSession::query()->where('thread_id', $resolvedThreadId)->delete();

            return null;
        }

        TaskAgentSession::query()->firstOrCreate(['thread_id' => $resolvedThreadId], [
            'task_group_id' => $group->id,
            'task_id' => $taskId,
            'node_id' => $node->id,
            'role' => $taskId === null ? 'reviewer' : 'implementer',
            'model' => $selection['model'],
            'effort' => $selection['options'][0]['value'] ?? null,
        ]);

        return $resolvedThreadId;
    }

    /**
     * @param  array{instanceId: string, model: string, options: list<array{id: string, value: string}>}  $selection
     */
    private function startOpeningTurn(Node $node, string $threadId, string $message, array $selection): void
    {
        try {
            $this->startTurn($node, $threadId, $message, $selection);
        } catch (T3DispatchException) {
            try {
                $this->startTurn($node, $threadId, $message, $selection);
            } catch (T3DispatchException $exception) {
                Log::error('T3 thread.turn.start failed after the thread was created.', [
                    'thread_id' => $threadId,
                    'exception' => $exception->getMessage(),
                ]);

                throw $exception;
            }
        }
    }

    /**
     * @param  array{instanceId: string, model: string, options: list<array{id: string, value: string}>}  $selection
     */
    private function startTurn(Node $node, string $threadId, string $message, array $selection): void
    {
        $messageId = (string) Str::uuid();

        $this->dispatcher->dispatch($node, [
            'type' => 'thread.turn.start',
            'commandId' => (string) Str::uuid(),
            'threadId' => $threadId,
            'message' => [
                'messageId' => $messageId,
                'role' => 'user',
                'text' => $message,
                'attachments' => [],
            ],
            'modelSelection' => $selection,
            'runtimeMode' => 'full-access',
            'interactionMode' => 'default',
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
            'Orbit task group #'.$group->id,
            'Feature: '.$group->title,
            $group->brief,
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
        ]);
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
