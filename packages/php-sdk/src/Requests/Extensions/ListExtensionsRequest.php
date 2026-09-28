<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Extensions;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Extensions\ExtensionsResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ListExtensionsRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/api/v1/extensions';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): ExtensionsResponse
    {
        return ExtensionsResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }
}
