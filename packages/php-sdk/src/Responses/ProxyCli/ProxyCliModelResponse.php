<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\ProxyCli;

use Orbit\Sdk\GatewayApiException;
use SensitiveParameter;

final readonly class ProxyCliModelResponse
{
    public function __construct(
        public string $id,
        public string $provider,
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
        $provider = $data['provider'] ?? null;

        if (
            ! is_string($id)
            || $id === ''
            || strlen($id) > 255
            || ! is_string($provider)
            || $provider === ''
            || strlen($provider) > 128
        ) {
            throw new GatewayApiException('Gateway response contains invalid proxycli model.', requestId: $requestId);
        }

        return new self($id, $provider, $requestId);
    }

    /**
     * @return array{id: string, provider: string, request_id: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'provider' => $this->provider,
            'request_id' => $this->requestId,
        ];
    }
}
