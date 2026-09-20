<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\ProxyCli;

use InvalidArgumentException;
use Orbit\Sdk\Support\GatewayRequestId;
use SensitiveParameter;

final readonly class ProxyCliProvidersResponse
{
    /** @var list<ProxyCliProviderResponse> */
    public array $providers;

    public string $requestId;

    /**
     * @param  array<array-key, mixed>  $providers
     */
    public function __construct(
        array $providers,
        #[SensitiveParameter]
        string $requestId,
    ) {
        if (! array_is_list($providers)) {
            throw new InvalidArgumentException('Invalid proxycli provider collection.');
        }

        foreach ($providers as $provider) {
            if (! $provider instanceof ProxyCliProviderResponse) {
                throw new InvalidArgumentException('Invalid proxycli provider collection member.');
            }
        }

        /** @var list<ProxyCliProviderResponse> $providers */
        $this->providers = $providers;
        $this->requestId = GatewayRequestId::fromTransport($requestId) ?? $requestId;
    }

    /**
     * @return array{
     *     providers: list<array<string, mixed>>,
     *     request_id: string
     * }
     */
    public function toArray(): array
    {
        return [
            'providers' => array_map(
                static function (ProxyCliProviderResponse $provider): array {
                    $data = $provider->toArray();
                    unset($data['request_id']);

                    return $data;
                },
                $this->providers,
            ),
            'request_id' => $this->requestId,
        ];
    }
}
