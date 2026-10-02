<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Projects;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Projects\DevelopmentDeployStepResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class DestroyProjectDevelopmentDeployStepRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::DELETE;

    public function __construct(
        private readonly int $projectId,
        private readonly string $name,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/projects/{$this->projectId}/dev-deploy-steps/{$this->name}";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): DevelopmentDeployStepResponse
    {
        return DevelopmentDeployStepResponse::fromData($this->unwrapData($response), $this->successRequestId($response));
    }
}
