<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Activities;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Activities\ActivityResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ShowActivityRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        private readonly int $activityId,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/activities/{$this->activityId}";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): ActivityResponse
    {
        $data = $this->unwrapData($response);
        $requestId = $this->successRequestId($response);

        return ActivityResponse::fromGatewayData($data, $requestId);
    }
}
