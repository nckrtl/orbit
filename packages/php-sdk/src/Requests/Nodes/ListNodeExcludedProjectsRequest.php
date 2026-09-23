<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Nodes;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Projects\DevelopmentNodeExclusionResponse;
use Orbit\Sdk\Responses\Projects\DevelopmentNodeExclusionsResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ListNodeExcludedProjectsRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(private readonly int $nodeId) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/nodes/{$this->nodeId}/excluded-projects";
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
