<?php

declare(strict_types=1);

namespace App\Domain\Broadcasting;

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

    /** @param array<string, mixed> $data */
    public function broadcast(RecordEventType $type, int|string $id, array $data): void
    {
        try {
            // Best-effort: when no websocket role is active this leaves the
            // default `null` connection in place, and the event below still
            // fires but broadcasts nowhere.
            $this->realtime->configureBroadcasting();
            event(new RecordBroadcast($type, $id, $data));
        } catch (Throwable $exception) {
            Log::warning('Failed to broadcast a record event.', [
                'type' => $type->value,
                'id' => $id,
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
