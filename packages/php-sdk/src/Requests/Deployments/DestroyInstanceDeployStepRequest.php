<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Deployments;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Deployments\DeploymentStepResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class DestroyInstanceDeployStepRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::DELETE;

    public function __construct(
        private readonly int $appInstanceId,
        private readonly string $name,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/instances/{$this->appInstanceId}/deploy-steps/{$this->name}";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): DeploymentStepResponse
    {
        $data = $this->unwrapData($response);

        return new DeploymentStepResponse(
            is_string($data['name'] ?? null) ? $data['name'] : '',
            is_string($data['phase'] ?? null) ? $data['phase'] : '',
            is_string($data['command'] ?? null) ? $data['command'] : '',
            is_int($data['timeout_seconds'] ?? null) ? $data['timeout_seconds'] : 0,
            $this->successRequestId($response),
        );
    }
}
