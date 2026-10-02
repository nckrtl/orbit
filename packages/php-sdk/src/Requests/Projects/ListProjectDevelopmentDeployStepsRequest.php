<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Projects;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Projects\DevelopmentDeployStepResponse;
use Orbit\Sdk\Responses\Projects\DevelopmentDeployStepsResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ListProjectDevelopmentDeployStepsRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        private readonly int $projectId,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/projects/{$this->projectId}/dev-deploy-steps";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): DevelopmentDeployStepsResponse
    {
        $requestId = $this->successRequestId($response);
        $steps = [];

        foreach ($this->unwrapDataList($response) as $step) {
            $steps[] = DevelopmentDeployStepResponse::fromData($step);
        }

        return new DevelopmentDeployStepsResponse($steps, $requestId);
    }
}
