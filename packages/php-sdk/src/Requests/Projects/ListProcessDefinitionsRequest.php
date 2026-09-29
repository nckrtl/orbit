<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Projects;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Projects\ProjectRuntimeDefinitionResponse;
use Orbit\Sdk\Responses\Projects\ProjectRuntimeDefinitionsResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ListProcessDefinitionsRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(private readonly int $projectId) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/projects/{$this->projectId}/process-definitions";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): ProjectRuntimeDefinitionsResponse
    {
        $requestId = $this->successRequestId($response);
        $definitions = array_map(
            static fn (array $data): ProjectRuntimeDefinitionResponse => ProjectRuntimeDefinitionResponse::fromGatewayData(
                $data,
                $requestId,
            ),
            $this->unwrapDataList($response),
        );

        return new ProjectRuntimeDefinitionsResponse($definitions, $requestId);
    }
}
