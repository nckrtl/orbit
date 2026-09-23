<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Projects;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Projects\DevelopmentNodeExclusionResponse;
use Orbit\Sdk\Responses\Projects\DevelopmentNodeExclusionsResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ListProjectExcludedNodesRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(private readonly int $projectId) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/projects/{$this->projectId}/excluded-nodes";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): DevelopmentNodeExclusionsResponse
    {
        $requestId = $this->successRequestId($response);
        $exclusions = [];

        foreach ($this->unwrapDataList($response, true) as $entry) {
            $exclusions[] = DevelopmentNodeExclusionResponse::fromGatewayData($entry, $requestId);
        }

        return new DevelopmentNodeExclusionsResponse($exclusions, $requestId);
    }
}
