<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks\T3;

use App\Domain\Tasks\AgentThreadStart;
use App\Models\Node;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final readonly class T3ThreadCreator
{
    public function __construct(private T3Dispatcher $dispatcher) {}

    public function create(AgentThreadStart $intent): string
    {
        $node = $intent->node;
        $selection = T3ModelSelection::forModel($intent->model, $intent->effort);
        $title = $intent->title;
        $message = $intent->prompt;
        $threadId = (string) Str::uuid();
        $projectId = (string) Str::uuid();
        $createdAt = now()->toIso8601String();

        try {
            try {
                $this->dispatcher->dispatch($node, [
                    'type' => 'project.create',
                    'commandId' => (string) Str::uuid(),
                    'projectId' => $projectId,
                    'workspaceRoot' => $intent->workspace->checkout_path,
                    'title' => $intent->title,
                    'defaultModelSelection' => $selection,
                    'createdAt' => $createdAt,
                ]);
            } catch (T3DispatchException $exception) {
                if (! is_string($exception->existingProjectId)) {
                    Log::warning('T3 project.create failed and reported no existing project.', [
                        'task_group_id' => null,
                        'node_id' => $node->id,
                        'workspace_root' => $intent->workspace->checkout_path,
                        'exception' => $exception->getMessage(),
                    ]);

                    throw new T3DispatchException('T3 conversation creation failed.');
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
                'branch' => $intent->workspace->branch ?? $intent->workspace->name,
                'worktreePath' => $intent->workspace->checkout_path,
                'createdAt' => $createdAt,
            ]);
        } catch (T3DispatchException $exception) {
            Log::warning('T3 thread.create failed.', [
                'task_group_id' => null,
                'node_id' => $node->id,
                'project_id' => $projectId,
                'exception' => $exception->getMessage(),
            ]);

            throw new T3DispatchException('T3 conversation creation failed.');
        }

        $resolvedThreadId = $created['thread_id'] !== '' ? $created['thread_id'] : $threadId;

        try {
            $this->startOpeningTurn($node, $resolvedThreadId, $message, $selection);
        } catch (T3DispatchException) {
            throw new T3DispatchException('T3 conversation creation failed.');
        }

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
    public function startTurn(Node $node, string $threadId, string $message, array $selection): void
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
}
