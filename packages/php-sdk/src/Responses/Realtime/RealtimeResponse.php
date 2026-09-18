<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Realtime;

use Orbit\Sdk\GatewayApiException;

/**
 * TEMPORARY: local stand-in for the class PR #490 (branch `nck/gateway-events`) adds under the
 * same name and namespace; see the doc comment on `Requests\Realtime\ShowRealtimeRequest` for
 * why it exists and when to drop it.
 *
 * The Gateway's realtime endpoint configuration, or the fact that realtime is not configured.
 *
 * `GET /api/v1/realtime` always answers 200 with a `channel` (a stable name, currently
 * `"orbit"`); `url` and `key` are `null` when the Gateway has no Reverb endpoint configured. An
 * older Gateway that does not yet implement the route answers 404, which this request also maps
 * into `configured: false` instead of throwing. Either way a caller such as `orbit top` can fall
 * back to polling without treating "not configured" as a transport failure.
 */
final readonly class RealtimeResponse
{
    private function __construct(
        public bool $configured,
        public ?string $url,
        public ?string $key,
        public string $channel,
        public string $requestId,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(array $data, string $requestId): self
    {
        $url = $data['url'] ?? null;
        $key = $data['key'] ?? null;
        $channel = $data['channel'] ?? null;

        if (
            ($url !== null && (! is_string($url) || trim($url) === ''))
            || ($key !== null && (! is_string($key) || trim($key) === ''))
            || ! is_string($channel) || trim($channel) === ''
        ) {
            throw new GatewayApiException(
                'Gateway response contains invalid realtime configuration.',
                requestId: $requestId,
            );
        }

        return new self($url !== null && $key !== null, $url, $key, $channel, $requestId);
    }

    public static function notConfigured(string $requestId): self
    {
        return new self(false, null, null, 'orbit', $requestId);
    }

    /** @return array{configured: bool, url: string|null, key: string|null, channel: string, request_id: string} */
    public function toArray(): array
    {
        return [
            'configured' => $this->configured,
            'url' => $this->url,
            'key' => $this->key,
            'channel' => $this->channel,
            'request_id' => $this->requestId,
        ];
    }
}
