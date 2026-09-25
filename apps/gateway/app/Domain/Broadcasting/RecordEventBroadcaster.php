<?php

declare(strict_types=1);

namespace App\Domain\Broadcasting;

use Closure;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Broadcasts a record change to the `orbit` channel. A Reverb outage must
 * never fail the action that changed the record, so every broadcast failure
 * is logged and swallowed here. Broadcasting only runs when an active
 * `websocket` role assignment resolves a connection; otherwise this is a
 * no-op with no database access beyond the one lazy, memoized lookup.
 */
final readonly class RecordEventBroadcaster
{
    public function __construct(
        private RealtimeConnection $realtime,
    ) {}

    /**
     * Sends the event to the serving Reverb and, during a `websocket` move, also to the old Node's Reverb,
     * where the clients that connected before DNS moved still listen. A failure on one server never stops
     * the other.
     *
     * @param  array<string, mixed>  $data
     */
    public function broadcast(RecordEventType $type, int|string $id, array $data): void
    {
        $event = new RecordBroadcast($type, $id, $data);

        try {
            $connections = $this->realtime->all();
        } catch (Throwable $exception) {
            $this->failed($type, $id, $exception);

            return;
        }

        if ($connections === []) {
            // Best-effort: when no websocket role is active this leaves the default `null` connection in
            // place, and the event still fires but broadcasts nowhere.
            $this->send(static fn () => event($event), $type, $id);

            return;
        }

        foreach ($connections as $index => $connection) {
            if ($index === 0) {
                $this->send(function () use ($connection, $event): void {
                    $this->realtime->configureBroadcasting($connection);
                    event($event);
                }, $type, $id);

                continue;
            }

            // The old server of a move only gets a short try, and none for a while after it failed, so an
            // unreachable old Node never slows the broadcast to the serving server.
            if ($this->realtime->oldServerSkipped($connection)) {
                continue;
            }

            $reached = $this->send(function () use ($connection, $event): void {
                $this->realtime->configureBroadcasting($connection, oldServer: true);
                // The old Node's server gets the same payload directly, so listeners run once.
                Broadcast::purge('reverb');
                Broadcast::connection('reverb')->broadcast($event->broadcastOn(), $event->broadcastAs(), $event->broadcastWith());
            }, $type, $id);
            $this->realtime->recordOldServer($connection, $reached);
        }

        if (count($connections) > 1) {
            // Later broadcasts in this request start again from the serving server.
            Broadcast::purge('reverb');
        }
    }

    /** @param Closure(): mixed $operation */
    private function send(Closure $operation, RecordEventType $type, int|string $id): bool
    {
        try {
            $operation();

            return true;
        } catch (Throwable $exception) {
            $this->failed($type, $id, $exception);

            return false;
        }
    }

    private function failed(RecordEventType $type, int|string $id, Throwable $exception): void
    {
        Log::warning('Failed to broadcast a record event.', [
            'type' => $type->value,
            'id' => $id,
            'exception' => $exception->getMessage(),
        ]);
    }
}
