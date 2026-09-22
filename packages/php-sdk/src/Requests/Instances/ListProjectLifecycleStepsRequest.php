<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Instances;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Instances\LifecycleStepResponse;
use Orbit\Sdk\Responses\Instances\LifecycleStepsResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ListProjectLifecycleStepsRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        private readonly int $projectId,
        private readonly string $collection,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/projects/{$this->projectId}/{$this->collection}";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): LifecycleStepsResponse
    {
        $requestId = $this->successRequestId($response);
        $steps = [];

        foreach ($this->unwrapDataList($response) as $step) {
            $steps[] = LifecycleStepResponse::fromData($step);
        }

        return new LifecycleStepsResponse($steps, $requestId);
    }
}
