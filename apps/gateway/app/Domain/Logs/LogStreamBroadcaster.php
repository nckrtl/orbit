<?php

declare(strict_types=1);

namespace App\Domain\Logs;

use App\Domain\Broadcasting\RealtimeConnection;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Broadcast;
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
 *
 * During a `websocket` move every event also goes to the old Node's Reverb, where the viewers and
 * agents that connected before DNS moved still listen. That send is best-effort, as for record events.
 */
final readonly class LogStreamBroadcaster
{
    /**
     * The largest payload of one event, measured as the JSON string it travels in: the Reverb HTTP API
     * body carries the event's JSON as an escaped string. Reverb refuses a request over 10,000 bytes,
     * headers and the rest of the body included, so this leaves them about 1,500 bytes.
     */
    public const int PayloadLimit = 8_500;

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
        $sent = $this->send(new LogStreamBroadcast('private-log-stream.'.$streamId, 'log.lines', $this->envelope('log.lines', $streamId, [
            'sequence' => $sequence,
            'lines' => $lines,
            'dropped' => $dropped,
            'skipped' => $skipped,
        ])));

        if (! $sent) {
            throw new RuntimeException('No websocket role is active.');
        }
    }

    public function ended(string $streamId, LogStreamEndReason $reason): void
    {
        $this->publish(new LogStreamBroadcast('private-log-stream.'.$streamId, 'log.ended', $this->envelope('log.ended', $streamId, [
            'reason' => $reason->value,
        ])));
    }

    /**
     * The byte size of a `log.lines` payload for these lines as it travels in the Reverb HTTP API body,
     * so the relay can split its parts. Quotes and backslashes in a line count twice there.
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

        return strlen((string) json_encode((string) json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE)));
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
            $this->send($event);
        } catch (Throwable $exception) {
            $this->failed($event, $exception);
        }
    }

    /**
     * Sends the event to the serving Reverb, and during a `websocket` move to the old Node's Reverb too.
     * Returns false when no websocket role is active. A failure on the serving server throws after the
     * old server had its try; a failure on the old server is logged, and later sends skip it for a while.
     */
    private function send(LogStreamBroadcast $event): bool
    {
        $connections = $this->realtime->all();

        if ($connections === []) {
            return false;
        }

        $servingFailure = null;

        try {
            $this->realtime->configureBroadcasting($connections[0]);
            event($event);
        } catch (Throwable $exception) {
            $servingFailure = $exception;
        }

        foreach (array_slice($connections, 1) as $connection) {
            if ($this->realtime->oldServerSkipped($connection)) {
                continue;
            }

            try {
                $this->realtime->configureBroadcasting($connection, oldServer: true);
                Broadcast::purge('reverb');
                Broadcast::connection('reverb')->broadcast($event->broadcastOn(), $event->broadcastAs(), $event->broadcastWith());
                $this->realtime->recordOldServer($connection, true);
            } catch (Throwable $exception) {
                $this->failed($event, $exception);
                $this->realtime->recordOldServer($connection, false);
            }
        }

        if (count($connections) > 1) {
            // Later broadcasts in this process start again from the serving server.
            $this->realtime->configureBroadcasting($connections[0]);
            Broadcast::purge('reverb');
        }

        if ($servingFailure !== null) {
            throw $servingFailure;
        }

        return true;
    }

    private function failed(LogStreamBroadcast $event, Throwable $exception): void
    {
        Log::warning('Failed to publish a live log event.', ['event' => $event->name, 'exception' => $exception->getMessage()]);
    }
}
