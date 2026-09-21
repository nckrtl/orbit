<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskAgentStream;
use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use App\Models\TaskAgentSession;
use App\Models\TaskGroup;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final readonly class StreamTaskAgentSessionAction
{
    public function __construct(
        private RequireTasksExtensionAction $requireExtension,
        private TaskAgentStream $stream,
        private CommandActivityInputSanitizer $sanitizer,
    ) {}

    public function execute(TaskGroup $group, TaskAgentSession $session, ?int $afterSequence): StreamedResponse
    {
        $this->requireExtension->execute();
        abort_unless($session->task_group_id === $group->id, 404);
        $node = $session->node;
        if ($node === null || $node->status !== LifecycleStatus::Active) {
            throw new ResourceOperationException('tasks.agent_unavailable', 'The original agent Node is unavailable.', 409);
        }

        return response()->stream(function () use ($node, $session, $afterSequence): \Generator {
            yield "retry: 3000\n\n";
            try {
                foreach ($this->stream->events($node, $session->thread_id, $afterSequence) as $item) {
                    if (connection_aborted()) {
                        break;
                    }
                    $kind = $item['kind'] ?? null;
                    if ($kind === 'heartbeat') {
                        yield ": heartbeat\n\n";

                        continue;
                    }
                    if (! in_array($kind, ['snapshot', 'event', 'synchronized'], true)) {
                        continue;
                    }
                    $threadId = $kind === 'snapshot' ? data_get($item, 'snapshot.thread.id') : data_get($item, 'event.aggregateId');
                    if ($kind !== 'synchronized' && $threadId !== $session->thread_id) {
                        continue;
                    }
                    $sequence = $kind === 'snapshot' ? data_get($item, 'snapshot.snapshotSequence') : data_get($item, 'event.sequence');
                    $json = json_encode($this->sanitizer->sanitizeProperties($item), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
                    $token = config('orbit.t3.token');
                    if (is_string($token) && $token !== '') {
                        $encoded = json_encode($token, JSON_THROW_ON_ERROR);
                        $json = str_replace(substr($encoded, 1, -1), '[REDACTED]', $json);
                    }
                    yield (is_int($sequence) ? 'id: '.$sequence."\n" : '')."event: agent\ndata: ".$json."\n\n";
                }
            } catch (Throwable) {
                yield "event: unavailable\ndata: {\"message\":\"Agent stream unavailable. Reconnecting…\"}\n\n";
            }
        }, 200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache, no-store', 'X-Accel-Buffering' => 'no']);
    }
}
