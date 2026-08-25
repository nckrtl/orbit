<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Apps;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Apps\AppResponse;
use Orbit\Sdk\Responses\Apps\AppsResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ListAppsRequest extends GatewayRequest
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/api/v1/apps';
    }

    public function createDtoFromResponse(Response $response): AppsResponse
    {
        $data = $this->unwrapDataList($response);
        $meta = $this->unwrapMeta($response);
        $requestId = is_string($meta['request_id'] ?? null) ? $meta['request_id'] : '';
        $apps = [];

        foreach ($data as $app) {
            $apps[] = AppResponse::fromGatewayData($app, $requestId);
        }

        return new AppsResponse($apps, $requestId);
    }
}
