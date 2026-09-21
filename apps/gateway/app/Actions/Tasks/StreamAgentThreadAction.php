<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\AgentDriverRegistry;
use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use App\Models\AgentThread;
use App\Models\TaskGroup;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final readonly class StreamAgentThreadAction
{
    public function __construct(
        private RequireTasksExtensionAction $requireExtension,
        private AgentDriverRegistry $drivers,
        private CommandActivityInputSanitizer $sanitizer,
    ) {}

    public function execute(TaskGroup $group, AgentThread $session, ?string $afterSequence): StreamedResponse
    {
        $this->requireExtension->execute();
        abort_unless($session->task_group_id === $group->id, 404);
        $node = $session->node;
        if ($node === null || $node->status !== LifecycleStatus::Active) {
            throw new ResourceOperationException('tasks.agent_unavailable', 'The original agent Node is unavailable.', 409);
        }

        return response()->stream(function () use ($session, $afterSequence): \Generator {
            yield "retry: 3000\n\n";
            try {
                foreach ($this->drivers->get($session->driver)->events($session, $afterSequence) as $event) {
                    if (connection_aborted()) {
                        break;
                    }
                    if ($event->threadId !== $session->id) {
                        continue;
                    }
                    if ($event->kind === 'heartbeat') {
                        yield ": heartbeat\n\n";

                        continue;
                    }
                    $data = $this->sanitizer->sanitizeProperties($event->toArray());
                    $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
                    $cursor = $event->cursor;
                    if ($cursor !== null && preg_match('/[\r\n\x00]/', $cursor) === 1) {
                        throw new \RuntimeException('Invalid agent event cursor.');
                    }
                    yield ($cursor !== null ? 'id: '.$cursor."\n" : '')."event: agent\ndata: ".$json."\n\n";
                }
            } catch (Throwable) {
                yield "event: unavailable\ndata: {\"message\":\"Agent stream unavailable. Reconnecting…\"}\n\n";
            }
        }, 200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache, no-store', 'X-Accel-Buffering' => 'no']);
    }
}
