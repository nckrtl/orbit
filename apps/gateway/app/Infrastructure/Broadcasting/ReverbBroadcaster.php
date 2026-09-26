<?php

declare(strict_types=1);

namespace App\Infrastructure\Broadcasting;

use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Support\Arr;
use Pusher\ApiErrorException;

/**
 * The `reverb` broadcaster, with a Reverb HTTP API body that carries text as UTF-8.
 *
 * The Pusher SDK encodes the event and then the body with plain `json_encode`, so each non-ASCII
 * character travels as `\\uXXXX`: an `é` takes 7 bytes and an emoji 14. Reverb refuses a request over
 * 10,000 bytes, so a live log line of such text was cut far below 8 KiB (ADR 0153). This broadcaster
 * encodes both levels with {@see self::JsonFlags}, so a character takes its UTF-8 size. Reverb decodes
 * the body as JSON, so viewers receive the same event.
 */
final class ReverbBroadcaster extends PusherBroadcaster
{
    public const int JsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

    /**
     * The body of one Reverb HTTP API event request.
     *
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $channels
     * @param  array<string, string>  $parameters
     */
    public static function body(string $event, array $payload, array $channels, array $parameters = []): string
    {
        return json_encode([
            'name' => $event,
            'data' => json_encode($payload, self::JsonFlags),
            'channels' => $channels,
            ...$parameters,
        ], self::JsonFlags);
    }

    /**
     * @param  array<int, mixed>  $channels
     * @param  string  $event
     * @param  array<string, mixed>  $payload
     */
    public function broadcast(array $channels, $event, array $payload = []): void
    {
        $socket = Arr::pull($payload, 'socket');
        $parameters = is_string($socket) ? ['socket_id' => $socket] : [];

        try {
            foreach (array_chunk($this->formatChannels($channels), 100) as $chunk) {
                $this->pusher->post('/events', self::body((string) $event, $payload, array_map(strval(...), $chunk), $parameters));
            }
        } catch (ApiErrorException $exception) {
            throw new BroadcastException(sprintf('Pusher error: %s.', $exception->getMessage()));
        }
    }
}
