<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Instances;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Instances\LifecycleStepResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class DestroyProjectLifecycleStepRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::DELETE;

    public function __construct(
        private readonly int $projectId,
        private readonly string $collection,
        private readonly string $name,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/projects/{$this->projectId}/{$this->collection}/{$this->name}";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): LifecycleStepResponse
    {
        return LifecycleStepResponse::fromData($this->unwrapData($response), $this->successRequestId($response));
    }
}
