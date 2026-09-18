<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Realtime;

use SensitiveParameter;

final readonly class RealtimeResponse
{
    public function __construct(
        public ?string $url,
        public ?string $key,
        public string $channel,
        public string $requestId,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(
        #[SensitiveParameter]
        array $data,
        #[SensitiveParameter]
        string $requestId,
    ): self {
        return new self(
            url: is_string($data['url'] ?? null) ? $data['url'] : null,
            key: is_string($data['key'] ?? null) ? $data['key'] : null,
            channel: is_string($data['channel'] ?? null) ? $data['channel'] : '',
            requestId: $requestId,
        );
    }

    /** @return array<string, ?string> */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'key' => $this->key,
            'channel' => $this->channel,
            'request_id' => $this->requestId,
        ];
    }
}
