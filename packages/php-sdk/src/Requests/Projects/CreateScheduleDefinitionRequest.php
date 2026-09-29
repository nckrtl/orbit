<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Projects;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Projects\ProjectRuntimeDefinitionResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasStringBody;
use SensitiveParameter;

final class CreateScheduleDefinitionRequest extends GatewayRequest implements HasBody
{
    use HasStringBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        private readonly int $projectId,
        #[SensitiveParameter]
        private readonly string $definition,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/projects/{$this->projectId}/schedule-definitions";
    }

    protected function defaultHeaders(): array
    {
        return ['Content-Type' => 'application/json'];
    }

    public function createDtoFromResponse(#[SensitiveParameter] Response $response): ProjectRuntimeDefinitionResponse
    {
        return ProjectRuntimeDefinitionResponse::fromGatewayData(
            $this->unwrapData($response),
            $this->successRequestId($response),
        );
    }

    protected function defaultBody(): string
    {
        return $this->definition;
    }
}
