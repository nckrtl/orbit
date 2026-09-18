<?php

declare(strict_types=1);

namespace App\Domain\Broadcasting;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Broadcasts a record change to the `orbit` channel. A Reverb outage must
 * never fail the action that changed the record, so every broadcast failure
 * is logged and swallowed here.
 */
final readonly class RecordEventBroadcaster
{
    /** @param array<string, mixed> $data */
    public function broadcast(RecordEventType $type, int|string $id, array $data): void
    {
        try {
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
