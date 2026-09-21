<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks\T3;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\AgentDriver;
use App\Domain\Tasks\AgentDriverException;
use App\Domain\Tasks\AgentInputRequest;
use App\Domain\Tasks\AgentObservation;
use App\Domain\Tasks\AgentThreadEvent;
use App\Domain\Tasks\AgentThreadStart;
use App\Domain\Tasks\TaskAgentDefaults;
use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use App\Models\AgentThread;
use App\Models\Node;
use Illuminate\Support\Str;

final readonly class T3Driver implements AgentDriver
{
    public function __construct(
        private T3Dispatcher $dispatcher,
        private T3ThreadReader $reader,
        private T3ThreadCreator $creator,
        private T3Stream $stream,
        private T3Projection $projection,
        private T3NodeEligibility $nodes,
    ) {}

    public function key(): string
    {
        return 't3';
    }

    public function allows(Node $node): bool
    {
        return $this->nodes->allows($node);
    }

    public function create(AgentThreadStart $intent): string
    {
        return $this->creator->create($intent);
    }

    public function send(AgentThread $thread, string $message): void
    {
        $this->creator->startTurn($this->node($thread), $thread->external_id, $message, T3ModelSelection::forModel($thread->model ?? '', $thread->effort ?? ($thread->role === 'reviewer' ? TaskAgentDefaults::ReviewerEffort : TaskAgentDefaults::ImplementerEffort)));
    }

    public function respond(AgentThread $thread, AgentInputRequest $request, array $answers): void
    {
        $response = match ($request->kind) {
            'approval' => ['type' => 'thread.approval.respond', 'decision' => ($answers['approve'] ?? false) === true ? 'acceptForSession' : 'decline'],
            'question' => ['type' => 'thread.user-input.respond', 'answers' => $answers],
            default => throw AgentDriverException::unsupported('this input request'),
        };
        $this->dispatcher->dispatch($this->node($thread), [
            ...$response, 'threadId' => $thread->external_id, 'requestId' => $request->id,
            'commandId' => (string) Str::uuid(), 'createdAt' => now()->toIso8601String(),
        ]);
    }

    public function interrupt(AgentThread $thread): void
    {
        $this->dispatcher->dispatch($this->node($thread), [
            'type' => 'thread.turn.interrupt', 'threadId' => $thread->external_id,
            'commandId' => (string) Str::uuid(), 'createdAt' => now()->toIso8601String(),
        ]);
    }

    public function observe(AgentThread $thread): AgentObservation
    {
        $snapshot = $this->reader->snapshot($this->node($thread), $thread->external_id);
        if ($snapshot === null) {
            throw new AgentDriverException('Agent observation unavailable.');
        }
        $id = data_get($snapshot, 'thread.id');
        if ($id !== null && $id !== $thread->external_id) {
            throw new AgentDriverException('Agent observation identity does not match.');
        }

        return $this->projection->observe($this->redact($snapshot, $this->node($thread)), $thread->state, $thread->error);
    }

    public function events(AgentThread $thread, ?string $cursor): iterable
    {
        if ($cursor !== null && (! ctype_digit($cursor) || strlen($cursor) > 16 || (int) $cursor > 9007199254740991)) {
            throw new AgentDriverException('Invalid agent stream cursor.');
        }
        $node = $this->node($thread);
        $raw = [];
        $messages = [];
        $metadata = [];
        $sequence = -1;
        $previous = $thread->state;
        $previousError = $thread->error;
        // Each connection starts with a baseline; cursors never imply a delta-only subscription.
        foreach ($this->stream->events($node, $thread->external_id, null) as $item) {
            $kind = $item['kind'] ?? null;
            if ($kind === 'heartbeat') {
                yield new AgentThreadEvent($thread->id, 'heartbeat');

                continue;
            }
            if ($kind === 'snapshot') {
                $snapshot = $item['snapshot'] ?? null;
                if (! is_array($snapshot) || data_get($snapshot, 'thread.id') !== $thread->external_id) {
                    continue;
                }
                $raw = $snapshot['thread'];
                $sequence = is_int($snapshot['snapshotSequence'] ?? null) ? $snapshot['snapshotSequence'] : -1;
                $observed = $this->projection->observe($this->redact($snapshot, $node), $previous, $previousError);
                $previous = $observed->state;
                $previousError = $observed->error;
                $metadata = $observed->toArray();
                unset($metadata['entries']);
                $messages = [];
                foreach ($raw['messages'] ?? [] as $message) {
                    if (is_array($message) && is_string($message['id'] ?? $message['messageId'] ?? null)) {
                        $messages[$message['id'] ?? $message['messageId']] = $message;
                    }
                }
                $raw = $this->streamMetadata($raw, $observed);
                yield new AgentThreadEvent($thread->id, 'snapshot', $observed->toArray(), $sequence >= 0 ? (string) $sequence : null);

                continue;
            }
            $event = $item['event'] ?? null;
            if ($kind !== 'event' || ! is_array($event) || ($event['aggregateId'] ?? null) !== $thread->external_id || ! is_int($event['sequence'] ?? null) || $event['sequence'] <= $sequence || $raw === []) {
                continue;
            }
            $sequence = $event['sequence'];
            $type = $event['type'] ?? null;
            if ($type === 'thread.message-sent') {
                $id = data_get($event, 'payload.messageId') ?? data_get($event, 'payload.id');
                if (! is_string($id) || $id === '') {
                    continue;
                }
                $single = $this->projection->apply(['messages' => isset($messages[$id]) ? [$messages[$id]] : []], $event);
                $messages[$id] = $single['messages'][0];
                $entry = $this->projection->observe($this->redact($single, $node))->entries[0] ?? null;
                if ($entry !== null) {
                    yield new AgentThreadEvent($thread->id, 'entry', ['entry' => $entry], (string) $sequence);
                }

                continue;
            }
            if (! in_array($type, ['thread.activity-appended', 'thread.session-set', 'thread.turn-start-requested', 'thread.turn-diff-completed'], true)) {
                continue;
            }
            $raw = $this->projection->apply($raw, $event);
            $observed = $this->projection->observe($this->redact(['thread' => $raw], $node), $previous, $previousError, includeEntries: false);
            $raw = $this->streamMetadata($raw, $observed);
            $previous = $observed->state;
            $previousError = $observed->error;
            $next = $observed->toArray();
            unset($next['entries']);
            if ($type === 'thread.activity-appended') {
                $single = $this->projection->apply([], $event);
                $entry = $this->projection->observe($this->redact($single, $node))->entries[0] ?? null;
                if ($entry !== null) {
                    yield new AgentThreadEvent($thread->id, 'entry', [...$next, 'entry' => $entry], (string) $sequence);
                }
            } elseif ($next !== $metadata) {
                yield new AgentThreadEvent($thread->id, 'state', $next, (string) $sequence);
            }
            $metadata = $next;
        }
    }

    /**
     * Keep only state, checkpoints, unresolved requests, and cumulative usage between events.
     * Transcript entries are emitted once and message fragments are indexed separately.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    private function streamMetadata(array $raw, AgentObservation $observed): array
    {
        $pending = ['approval' => [], 'question' => []];
        foreach ($observed->inputRequests as $request) {
            $pending[$request->kind][] = [...$request->details, 'requestId' => $request->id];
        }

        return [
            ...array_intersect_key($raw, array_flip(['id', 'session', 'sess', 'latestTurn', 'latest_turn', 'error', 'checkpoints'])),
            'pendingApprovals' => $pending['approval'], 'pendingUserInputs' => $pending['question'],
            'usage' => ['totalProcessedTokens' => $observed->tokens, 'usedTokens' => $observed->tokens],
        ];
    }

    /** @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function redact(array $data, Node $node): array
    {
        $token = new T3Connection()->credentials($node)['token'];
        if ($token !== null) {
            array_walk_recursive($data, static function (mixed &$value) use ($token): void {
                if (is_string($value)) {
                    $value = str_replace($token, '[REDACTED]', $value);
                }
            });
        }

        return new CommandActivityInputSanitizer()->sanitizeProperties($data);
    }

    private function node(AgentThread $thread): Node
    {
        $node = $thread->node;
        if ($node === null || $node->status !== LifecycleStatus::Active || $thread->runtime_key !== 'node:'.$node->id) {
            throw new AgentDriverException('The original agent Node is unavailable.');
        }

        return $node;
    }
}
