<?php

declare(strict_types=1);

namespace App\Support\Realtime;

use DateTimeImmutable;
use Exception;
use JsonException;

/** One decoded `{type, id, at, data}` envelope carried by a Pusher-protocol channel event. */
final readonly class RealtimeEvent
{
    /** @param  array<string, mixed>  $data */
    public function __construct(
        public string $type,
        public int $id,
        public DateTimeImmutable $at,
        public array $data,
    ) {}

    /**
     * Decode one channel event's Pusher `data` payload into its envelope.
     *
     * The Pusher `data` payload is a JSON-encoded string in production but may already be
     * decoded (an array) when a caller replays a fixture or a fake transport.
     */
    public static function fromChannelPayload(string $eventName, mixed $payload): self
    {
        $decoded = $payload;

        if (is_string($decoded)) {
            try {
                $decoded = json_decode($decoded, associative: true, depth: 32, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RealtimeProtocolException(
                    "Realtime event [{$eventName}] payload is not valid JSON.",
                    previous: $exception,
                );
            }
        }

        if (! is_array($decoded)) {
            throw new RealtimeProtocolException("Realtime event [{$eventName}] payload is malformed.");
        }

        $type = $decoded['type'] ?? null;

        if (! is_string($type) || $type === '') {
            throw new RealtimeProtocolException("Realtime event [{$eventName}] is missing its type.");
        }

        $id = $decoded['id'] ?? null;

        if (! is_int($id)) {
            throw new RealtimeProtocolException("Realtime event [{$type}] is missing a numeric id.");
        }

        $at = $decoded['at'] ?? null;

        if (! is_string($at) || $at === '') {
            throw new RealtimeProtocolException("Realtime event [{$type}] is missing its timestamp.");
        }

        try {
            $timestamp = new DateTimeImmutable($at);
        } catch (Exception $exception) {
            throw new RealtimeProtocolException("Realtime event [{$type}] has an invalid timestamp.", previous: $exception);
        }

        $data = $decoded['data'] ?? null;

        if (! is_array($data)) {
            throw new RealtimeProtocolException("Realtime event [{$type}] is missing its data record.");
        }

        return new self($type, $id, $timestamp, self::stringKeyedArray($data));
    }

    /** @return array{type: string, id: int, at: string, data: array<string, mixed>} */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'id' => $this->id,
            'at' => $this->at->format(DATE_ATOM),
            'data' => $this->data,
        ];
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<string, mixed>
     */
    private static function stringKeyedArray(array $value): array
    {
        $result = [];

        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $result[$key] = $item;
            }
        }

        return $result;
    }
}
