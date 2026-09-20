<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\GitHub;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\GitHub\GitHubAppResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use SensitiveParameter;

final class ShowGitHubAppRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/api/v1/github/app';
    }

    public function createDtoFromResponse(#[SensitiveParameter] Response $response): GitHubAppResponse
    {
        return GitHubAppResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }
}
