<?php

declare(strict_types=1);

namespace App\Support\Logs;

use App\Support\Realtime\RealtimeProtocolException;
use JsonException;

/**
 * One server event on a `private-log-stream.{stream}` channel: `log.lines` with new lines, or
 * `log.ended` with the reason the stream closed. Client events and other names are not log
 * stream events, so decode() drops them.
 */
final readonly class LogStreamEvent
{
    public const string LINES = 'log.lines';

    public const string ENDED = 'log.ended';

    /** @param  list<string>  $lines */
    private function __construct(
        public string $type,
        public string $streamId,
        public int $sequence = 0,
        public array $lines = [],
        public int $dropped = 0,
        public int $skipped = 0,
        public string $reason = '',
    ) {}

    /**
     * Decode one channel event's Pusher `data` payload, which is a JSON string in production and
     * may already be an array when a test replays it.
     *
     * @throws RealtimeProtocolException when a log stream event violates its contract.
     */
    public static function decode(string $eventName, mixed $payload): ?self
    {
        if ($eventName !== self::LINES && $eventName !== self::ENDED) {
            return null;
        }

        if (is_string($payload)) {
            try {
                $payload = json_decode($payload, associative: true, depth: 16, flags: JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RealtimeProtocolException("Log stream event [{$eventName}] is not valid JSON.", previous: $exception);
            }
        }

        if (! is_array($payload) || ($payload['type'] ?? null) !== $eventName || ! is_array($payload['data'] ?? null)) {
            throw new RealtimeProtocolException("Log stream event [{$eventName}] is malformed.");
        }

        $streamId = $payload['id'] ?? null;

        if (! is_string($streamId) || preg_match('/\A[0-9a-f]{32}\z/D', $streamId) !== 1) {
            throw new RealtimeProtocolException("Log stream event [{$eventName}] has an invalid stream ID.");
        }

        $data = $payload['data'];

        if ($eventName === self::ENDED) {
            $reason = $data['reason'] ?? null;

            if (! is_string($reason) || preg_match('/\A[a-z][a-z_]{0,63}\z/D', $reason) !== 1) {
                throw new RealtimeProtocolException('Log stream event [log.ended] has an invalid reason.');
            }

            return new self(self::ENDED, $streamId, reason: $reason);
        }

        $sequence = $data['sequence'] ?? null;
        $lines = $data['lines'] ?? null;
        $dropped = $data['dropped'] ?? null;
        $skipped = $data['skipped'] ?? null;

        if (
            ! is_int($sequence) || $sequence < 1
            || ! is_array($lines) || ! array_is_list($lines)
            || ! is_int($dropped) || $dropped < 0
            || ! is_int($skipped) || $skipped < 0
        ) {
            throw new RealtimeProtocolException('Log stream event [log.lines] is malformed.');
        }

        foreach ($lines as $line) {
            if (! is_string($line)) {
                throw new RealtimeProtocolException('Log stream event [log.lines] carries a line that is not text.');
            }
        }

        /** @var list<string> $lines */
        return new self(self::LINES, $streamId, $sequence, $lines, $dropped, $skipped);
    }
}
