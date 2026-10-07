<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Gateway;

use InvalidArgumentException;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Gateway\DesiredFleetStateResponse;
use Orbit\Sdk\Responses\Gateway\GatewayStatusResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ShowGatewayStatusRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/api/v1/gateway/status';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): GatewayStatusResponse
    {
        $data = $this->unwrapData($response);
        $requestId = $this->successRequestId($response);

        return new GatewayStatusResponse(
            name: is_string($data['name'] ?? null) ? $data['name'] : '',
            status: is_string($data['status'] ?? null) ? $data['status'] : '',
            version: is_string($data['version'] ?? null) ? $data['version'] : '',
            phpVersion: is_string($data['php_version'] ?? null) ? $data['php_version'] : '',
            laravelVersion: is_string($data['laravel_version'] ?? null) ? $data['laravel_version'] : '',
            requestId: $requestId,
            desiredFleetState: $this->desiredFleetState($data['desired_fleet_state'] ?? null, $requestId),
        );
    }

    /** A malformed desired state is left out rather than shown with invented values. */
    private function desiredFleetState(mixed $data, string $requestId): ?DesiredFleetStateResponse
    {
        if (! is_array($data)) {
            return null;
        }

        try {
            return DesiredFleetStateResponse::fromGatewayData($data, $requestId);
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
