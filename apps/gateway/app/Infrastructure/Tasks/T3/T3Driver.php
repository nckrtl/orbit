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
        $this->creator->startTurn($this->node($thread), $thread->external_id, $message, T3ModelSelection::forModel($thread->model ?? '', $thread->effort ?? 'low'));
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
        $sequence = -1;
        $previous = $thread->state;
        $previousError = $thread->error;
        // Each subscription supplies a complete baseline so resumed deltas can be normalized.
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
            } elseif ($kind === 'event') {
                $event = $item['event'] ?? null;
                if (! is_array($event) || ($event['aggregateId'] ?? null) !== $thread->external_id || ! is_int($event['sequence'] ?? null) || $event['sequence'] <= $sequence || $raw === []) {
                    continue;
                }
                $raw = $this->projection->apply($raw, $event);
                $sequence = $event['sequence'];
            } else {
                continue;
            }
            $observation = $this->projection->observe($this->redact(['thread' => $raw, 'snapshotSequence' => $sequence], $node), $previous, $previousError);
            $previous = $observation->state;
            $previousError = $observation->error;
            $data = $observation->toArray();
            yield new AgentThreadEvent($thread->id, 'snapshot', $data, $sequence >= 0 ? (string) $sequence : null);
        }
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
