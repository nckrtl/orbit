<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Deployments;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Deployments\DeploymentStepResponse;
use Orbit\Sdk\Responses\Deployments\DeployStepsResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ListInstanceDeployStepsRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        private readonly int $appInstanceId,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/instances/{$this->appInstanceId}/deploy-steps";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): DeployStepsResponse
    {
        $requestId = $this->successRequestId($response);
        $steps = [];

        foreach ($this->unwrapDataList($response) as $step) {
            $steps[] = new DeploymentStepResponse(
                is_string($step['name'] ?? null) ? $step['name'] : '',
                is_string($step['phase'] ?? null) ? $step['phase'] : '',
                is_string($step['command'] ?? null) ? $step['command'] : '',
                is_int($step['timeout_seconds'] ?? null) ? $step['timeout_seconds'] : 0,
            );
        }

        return new DeployStepsResponse($steps, $requestId);
    }
}
