<?php

declare(strict_types=1);

namespace App\Infrastructure\Broadcasting;

use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Support\Arr;
use Pusher\ApiErrorException;
use Pusher\PusherException;
use Stringable;
use TypeError;

/**
 * The `reverb` broadcaster, with a Reverb HTTP API body that carries text as UTF-8.
 *
 * The Pusher SDK encodes the event and then the body with plain `json_encode`, so each non-ASCII
 * character travels as `\\uXXXX`: an `é` takes 7 bytes and an emoji 14. Reverb refuses a request over
 * 10,000 bytes, so a live log line of such text was cut far below 8 KiB (ADR 0153). This broadcaster
 * encodes both levels with {@see self::JsonFlags}, so a character takes its UTF-8 size. Reverb decodes
 * the body as JSON, so viewers receive the same event. An invalid channel name or socket ID is refused
 * before that request, with the same error Pusher's `trigger` raises, and channels are still sent in
 * groups of 100.
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
        $parameters = $socket !== null ? ['socket_id' => $this->stringSocketId($socket)] : [];

        try {
            foreach (array_chunk($this->formatChannels($channels), 100) as $chunk) {
                /** @var list<string> $names */
                $names = array_map(strval(...), $chunk);
                $this->refuseInvalidChannelsOrSocket($names, $parameters['socket_id'] ?? null);
                $this->pusher->post('/events', self::body((string) $event, $payload, $names, $parameters));
            }
        } catch (ApiErrorException $exception) {
            throw new BroadcastException(sprintf('Pusher error: %s.', $exception->getMessage()));
        }
    }

    /**
     * The channel-name and socket-ID checks from Pusher's `validate_channels` and `validate_socket_id`.
     *
     * `trigger` runs them before it posts. `post` does not, so a direct post would send a name or socket
     * ID that the stock driver refuses. A non-null socket is coerced to the string `validate_socket_id`
     * receives. Each group is already at most 100 channels.
     *
     * @param  list<string>  $channels
     */
    private function refuseInvalidChannelsOrSocket(array $channels, ?string $socketId): void
    {
        foreach ($channels as $channel) {
            if (preg_match('/\A#?[-a-zA-Z0-9_=@,.;]+\z/', $channel) !== 1) {
                throw new PusherException('Invalid channel name '.$channel);
            }
        }

        if ($socketId !== null && preg_match('/\A\d+\.\d+\z/', $socketId) !== 1) {
            throw new PusherException('Invalid socket ID '.$socketId);
        }
    }

    /**
     * The string Pusher coerces a socket ID to before `validate_socket_id` checks it.
     */
    private function stringSocketId(mixed $socket): string
    {
        if (is_string($socket) || is_int($socket) || is_float($socket) || is_bool($socket) || $socket instanceof Stringable) {
            return (string) $socket;
        }

        throw new TypeError(sprintf('Socket ID must be a string, %s given.', get_debug_type($socket)));
    }
}
