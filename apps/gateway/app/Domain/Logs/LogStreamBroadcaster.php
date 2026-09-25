<?php

declare(strict_types=1);

namespace App\Domain\Logs;

use App\Domain\Broadcasting\RealtimeConnection;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Publishes the server events of live log streams through the Reverb HTTP API (ADR 0153).
 *
 * A failed publish never fails the request or the relay pass that caused it: the viewer notices a
 * gap, an expired lease, or a closed stream, and falls back or reopens.
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

    /** @param list<string> $lines */
    public function lines(string $streamId, int $sequence, array $lines, int $dropped, int $skipped): void
    {
        $this->publish(new LogStreamBroadcast('private-log-stream.'.$streamId, 'log.lines', $this->envelope('log.lines', $streamId, [
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
