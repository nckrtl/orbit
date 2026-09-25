<?php

declare(strict_types=1);

namespace App\Domain\Logs;

use App\Domain\Broadcasting\RealtimeConnection;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Publishes the server events of live log streams through the Reverb HTTP API (ADR 0153).
 *
 * A failed `log-streams.changed` or `log.ended` never fails the request or the relay run that caused
 * it: the agent reads its stream list again on its next prompt, and the viewer's next renewal finds
 * the stream closed. A failed `log.lines` throws instead, so the relay run fails and publishes the
 * lines again, in order, instead of losing them.
 */
final readonly class LogStreamBroadcaster
{
    /** The largest JSON payload of one event, safely under Reverb's 10,000-byte message limit. */
    public const int PayloadLimit = 9_000;

    public function __construct(private RealtimeConnection $realtime) {}

    /** Prompts a Node's agent to fetch its stream list again. The event carries no data. */
    public function changed(int $nodeId): void
    {
        $this->publish(new LogStreamBroadcast("presence-node-logs.{$nodeId}", 'log-streams.changed', []));
    }

    /**
     * @param  list<string>  $lines
     *
     * @throws RuntimeException when Reverb is not configured or refused the event.
     */
    public function lines(string $streamId, int $sequence, array $lines, int $dropped, int $skipped): void
    {
        if (! $this->realtime->configureBroadcasting()) {
            throw new RuntimeException('No websocket role is active.');
        }

        event(new LogStreamBroadcast('private-log-stream.'.$streamId, 'log.lines', $this->envelope('log.lines', $streamId, [
            'sequence' => $sequence,
            'lines' => $lines,
            'dropped' => $dropped,
            'skipped' => $skipped,
        ])));
    }

    public function ended(string $streamId, LogStreamEndReason $reason): void
    {
        $this->publish(new LogStreamBroadcast('private-log-stream.'.$streamId, 'log.ended', $this->envelope('log.ended', $streamId, [
            'reason' => $reason->value,
        ])));
    }

    /**
     * The byte size of a `log.lines` payload for these lines, so the relay can split its parts.
     *
     * @param  list<string>  $lines
     */
    public static function linesPayloadSize(string $streamId, array $lines): int
    {
        $payload = [
            'type' => 'log.lines',
            'id' => $streamId,
            'at' => '9999-12-31T23:59:59+00:00',
            'data' => ['sequence' => PHP_INT_MAX, 'lines' => $lines, 'dropped' => PHP_INT_MAX, 'skipped' => PHP_INT_MAX],
        ];

        return strlen((string) json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function envelope(string $type, string $streamId, array $data): array
    {
        return ['type' => $type, 'id' => $streamId, 'at' => Carbon::now()->format(DateTimeInterface::ATOM), 'data' => $data];
    }

    private function publish(LogStreamBroadcast $event): void
    {
        try {
            if (! $this->realtime->configureBroadcasting()) {
                return;
            }

            event($event);
        } catch (Throwable $exception) {
            Log::warning('Failed to publish a live log event.', ['event' => $event->name, 'exception' => $exception->getMessage()]);
        }
    }
}
