<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\AgentObservation;
use App\Domain\Tasks\AgentThreadObserver;
use App\Domain\Tasks\AgentThreadState;
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
        private AgentThreadObserver $observer,
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
                    if (in_array($event->kind, ['snapshot', 'state'], true)) {
                        $this->observer->record($session, new AgentObservation(
                            state: is_string($data['state'] ?? null) ? AgentThreadState::tryFrom($data['state']) : null,
                            tokens: is_int($data['tokens'] ?? null) ? $data['tokens'] : null,
                            linesAdded: is_int($data['lines_added'] ?? null) ? $data['lines_added'] : null,
                            linesDeleted: is_int($data['lines_deleted'] ?? null) ? $data['lines_deleted'] : null,
                            error: is_string($data['error'] ?? null) ? $data['error'] : null,
                        ));
                    }
                    $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
                    $cursor = $event->cursor;
                    if ($cursor !== null && preg_match('/[\r\n\x00]/', $cursor) === 1) {
                        throw new \RuntimeException('Invalid agent event cursor.');
                    }
                    yield ($cursor !== null ? 'id: '.$cursor."\n" : '')."event: agent\ndata: ".$json."\n\n";
                }
            } catch (Throwable) {
                $session->update(['observation_error' => 'Agent stream unavailable.']);
                yield "event: unavailable\ndata: {\"message\":\"Agent stream unavailable. Reconnecting…\"}\n\n";
            }
        }, 200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'no-cache, no-store', 'X-Accel-Buffering' => 'no']);
    }
}
