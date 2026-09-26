<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Logs;

use LogicException;
use Orbit\Sdk\GatewayApiException;
use SensitiveParameter;

/**
 * An open live log stream: the private channel it publishes on and the Pusher signature that lets
 * the requesting socket, and only that socket, subscribe to it.
 */
final readonly class LogStreamResponse
{
    public function __construct(
        public string $id,
        public string $channel,
        #[SensitiveParameter]
        public string $auth,
        public int $lines,
        public int $leaseSeconds,
        public int $renewSeconds,
        public string $requestId,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(
        #[SensitiveParameter]
        array $data,
        #[SensitiveParameter]
        string $requestId,
    ): self {
        $id = $data['id'] ?? null;
        $channel = $data['channel'] ?? null;
        $auth = $data['auth'] ?? null;
        $lines = $data['lines'] ?? null;
        $leaseSeconds = $data['lease_seconds'] ?? null;
        $renewSeconds = $data['renew_seconds'] ?? null;

        if (
            ! LogStreamId::valid($id)
            || $channel !== "private-log-stream.{$id}"
            || ! is_string($auth)
            || $auth === ''
            || strlen($auth) > 1024
            || preg_match('/[\x00-\x20\x7f]/', $auth) === 1
            || ! is_int($lines)
            || $lines < 1
            || $lines > 1000
            || ! is_int($leaseSeconds)
            || $leaseSeconds < 1
            || ! is_int($renewSeconds)
            || $renewSeconds < 1
        ) {
            throw new GatewayApiException('Gateway response contains an invalid log stream.', requestId: $requestId);
        }

        return new self($id, $channel, $auth, $lines, $leaseSeconds, $renewSeconds, $requestId);
    }

    /**
     * The stream without its subscription signature.
     *
     * @return array{id: string, channel: string, lines: int, lease_seconds: int, renew_seconds: int, request_id: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'channel' => $this->channel,
            'lines' => $this->lines,
            'lease_seconds' => $this->leaseSeconds,
            'renew_seconds' => $this->renewSeconds,
            'request_id' => $this->requestId,
        ];
    }

    /** @return array{id: string, channel: string, lines: int, lease_seconds: int, renew_seconds: int, request_id: string} */
    public function __debugInfo(): array
    {
        return $this->toArray();
    }

    /** @return array<never, never> */
    public function __serialize(): array
    {
        throw new LogicException('Log stream responses cannot be serialized.');
    }
}
