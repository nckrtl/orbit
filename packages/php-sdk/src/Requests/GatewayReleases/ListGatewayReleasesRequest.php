<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\GatewayReleases;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\GatewayReleases\GatewayReleaseResponse;
use Orbit\Sdk\Responses\GatewayReleases\GatewayReleasesResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

/** Lists the newest Gateway release records, newest first. */
final class ListGatewayReleasesRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/api/v1/gateway/releases';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): GatewayReleasesResponse
    {
        $requestId = $this->successRequestId($response);

        return new GatewayReleasesResponse(
            array_map(
                static fn (array $release): GatewayReleaseResponse => GatewayReleaseResponse::fromGatewayData($release, $requestId),
                $this->unwrapDataList($response, rejectMalformed: true),
            ),
            $requestId,
        );
    }
}
