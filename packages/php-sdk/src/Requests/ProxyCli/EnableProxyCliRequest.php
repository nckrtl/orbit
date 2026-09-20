<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\ProxyCli;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\ProxyCli\ProxyCliStatusResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;
use SensitiveParameter;

final class EnableProxyCliRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        private readonly int $nodeId,
        private readonly string $cacheConnection,
        private readonly string $cliproxyUrl,
        #[SensitiveParameter]
        private readonly string $cliproxyManagementKey,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/proxycli';
    }

    /**
     * @return array{node_id: int, cache_connection: string, cliproxy_url: string, cliproxy_management_key: string}
     */
    protected function defaultBody(): array
    {
        return [
            'node_id' => $this->nodeId,
            'cache_connection' => $this->cacheConnection,
            'cliproxy_url' => $this->cliproxyUrl,
            'cliproxy_management_key' => $this->cliproxyManagementKey,
        ];
    }

    public function createDtoFromResponse(#[SensitiveParameter] Response $response): ProxyCliStatusResponse
    {
        return ProxyCliStatusResponse::fromGatewayData(
            $this->unwrapData($response),
            $this->successRequestId($response),
        );
    }
}
