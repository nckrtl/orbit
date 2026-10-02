<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\AgentDriverException;
use App\Domain\Tasks\AgentInputRequest;
use App\Domain\Tasks\AgentObservation;
use App\Domain\Tasks\AgentThreadStart;
use App\Domain\Tasks\AgentThreadState;
use App\Models\AgentThread;
use App\Models\Node;
use Illuminate\Support\Str;

/** In-memory task-engine fixture. It never calls an agent runtime. */
final class SnapshotAgentDriver extends FakeAgentDriver
{
    public function __construct(private ?AgentCommandDispatcher $dispatcher = null, private ?AgentSnapshotReader $reader = null)
    {
        parent::__construct('pi');
    }

    public function create(AgentThreadStart $intent): string
    {
        $id = $intent->externalId ?? (string) Str::uuid();
        $result = $this->dispatch($intent->node, [
            'type' => 'create', 'threadId' => $id, 'title' => $intent->title,
            'model' => $intent->model, 'effort' => $intent->effort,
            'worktreePath' => $intent->workspace->checkout_path, 'branch' => $intent->workspace->branch,
            'message' => ['text' => $intent->prompt],
        ]);

        return $result['thread_id'] !== '' ? $result['thread_id'] : $id;
    }

    public function send(AgentThread $thread, string $message, ?string $key = null): void
    {
        $key ??= (string) Str::uuid();
        $this->dispatch($this->node($thread), [
            'type' => 'send', 'threadId' => $thread->external_id, 'commandId' => $key,
            'message' => ['messageId' => $key, 'text' => $message], 'model' => $thread->model, 'effort' => $thread->effort,
        ]);
    }

    public function respond(AgentThread $thread, AgentInputRequest $request, array $answers): void
    {
        $this->dispatch($this->node($thread), ['type' => 'respond', 'threadId' => $thread->external_id, 'requestId' => $request->id, 'answers' => $answers]);
    }

    public function observe(AgentThread $thread): AgentObservation
    {
        $snapshot = ($this->reader ?? app(AgentSnapshotReader::class))->snapshot($this->node($thread), $thread->external_id);
        if ($snapshot === null) {
            throw new AgentDriverException('Agent observation unavailable.');
        }
        $data = $snapshot['thread'] ?? $snapshot;
        if (isset($data['id']) && $data['id'] !== $thread->external_id) {
            throw new AgentDriverException('Agent observation identity does not match.');
        }
        $session = $data['session'] ?? [];
        $status = $session['status'] ?? $data['latestTurn']['state'] ?? null;
        $state = match ($status) {
            'running', 'working', 'starting' => AgentThreadState::Working,
            'idle', 'ready', 'stopped' => AgentThreadState::Idle,
            'done', 'completed' => AgentThreadState::Done,
            'error', 'failed' => AgentThreadState::Failed,
            default => null,
        };
        if ($state === AgentThreadState::Idle && ($data['latestTurn']['state'] ?? null) === 'completed') {
            $state = AgentThreadState::Done;
        }
        $requests = [];
        if (! in_array($state, [AgentThreadState::Working, AgentThreadState::Done, AgentThreadState::Failed], true)) {
            foreach (['pendingUserInputs' => 'question', 'pendingApprovals' => 'approval'] as $field => $kind) {
                foreach ($data[$field] ?? [] as $request) {
                    $requests[] = new AgentInputRequest($request['requestId'], $kind, $request);
                }
            }
        }
        if ($requests !== []) {
            $state = AgentThreadState::AskingForInput;
        }
        $tokens = $session['totalProcessedTokens'] ?? null;
        foreach ($data['activities'] ?? [] as $activity) {
            $value = $activity['payload']['usage']['totalProcessedTokens'] ?? null;
            if (is_int($value)) {
                $tokens = max($tokens ?? 0, $value);
            }
        }
        $added = $deleted = null;
        foreach ($data['checkpoints'] ?? [] as $checkpoint) {
            foreach ($checkpoint['files'] ?? [] as $file) {
                $added = ($added ?? 0) + ($file['additions'] ?? 0);
                $deleted = ($deleted ?? 0) + ($file['deletions'] ?? 0);
            }
        }
        $entries = [];
        foreach ($data['messages'] ?? [] as $index => $message) {
            $entries[] = ['id' => (string) ($message['id'] ?? $index), 'kind' => 'message', 'label' => $message['role'] ?? 'assistant', 'text' => $message['text'] ?? '', 'at' => $message['createdAt'] ?? ''];
        }

        return new AgentObservation(
            state: $state, inputRequests: $requests, entries: $entries, tokens: $tokens,
            linesAdded: $data['latestTurn']['diffSummary']['additions'] ?? $added,
            linesDeleted: $data['latestTurn']['diffSummary']['deletions'] ?? $deleted,
            error: $session['lastError'] ?? null,
            turnId: $data['latestTurn']['id'] ?? $data['latestTurn']['turnId'] ?? $session['activeTurnId'] ?? null,
            sessionUpdatedAt: $session['updatedAt'] ?? null,
        );
    }

    private function node(AgentThread $thread): Node
    {
        $node = $thread->node;
        if ($node === null || $node->status !== LifecycleStatus::Active) {
            throw new AgentDriverException('The original agent Node is unavailable.');
        }

        return $node;
    }

    /**
     * @param  array<string, mixed>  $command
     * @return array{sequence: int, thread_id: string}
     */
    private function dispatch(Node $node, array $command): array
    {
        return ($this->dispatcher ?? app(AgentCommandDispatcher::class))->dispatch($node, $command);
    }
}
