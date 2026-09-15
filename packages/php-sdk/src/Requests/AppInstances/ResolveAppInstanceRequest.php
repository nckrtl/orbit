<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\AppInstances;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\AppInstances\ResolvedAppInstanceResponse;
use Orbit\Sdk\Support\InstanceResolutionDecoder;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use SensitiveParameter;

final class ResolveAppInstanceRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(#[SensitiveParameter] private readonly string $domain) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/instances/resolve';
    }

    /** @return array<string, string> */
    protected function defaultQuery(): array
    {
        return ['domain' => $this->domain];
    }

    public function hasRequestFailed(#[SensitiveParameter] Response $response): ?bool
    {
        InstanceResolutionDecoder::guardBody($response->body(), $response->header('X-Orbit-Request-Id'));

        return parent::hasRequestFailed($response);
    }

    public function createDtoFromResponse(#[SensitiveParameter] Response $response): ResolvedAppInstanceResponse
    {
        return InstanceResolutionDecoder::decode($response->body(), $this->domain, $response->header('X-Orbit-Request-Id'));
    }
}
