<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\AppInstances;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Dependencies\InstanceDependencyInventoryResponse;
use Orbit\Sdk\Support\DependencyInventoryDecoder;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use SensitiveParameter;

final class ShowInstanceDependenciesRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(private readonly int $instanceId) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/instances/{$this->instanceId}/dependencies";
    }

    public function hasRequestFailed(#[SensitiveParameter] Response $response): ?bool
    {
        DependencyInventoryDecoder::guardBody($response->body(), $response->header('X-Orbit-Request-Id'));

        return parent::hasRequestFailed($response);
    }

    public function createDtoFromResponse(#[SensitiveParameter] Response $response): InstanceDependencyInventoryResponse
    {
        return DependencyInventoryDecoder::decode(
            $response->body(), $this->instanceId, $response->header('X-Orbit-Request-Id'), false,
        );
    }
}
