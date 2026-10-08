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
            release: is_string($data['release'] ?? null) ? $data['release'] : null,
            releaseSha: is_string($data['release_sha'] ?? null) ? $data['release_sha'] : null,
            autoRelease: $this->autoRelease($data['auto_release'] ?? null),
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

    /** @return array{enabled: bool, paused: bool, last_checked_at: string|null, last_result: string|null}|null */
    private function autoRelease(mixed $value): ?array
    {
        if (! is_array($value) || ! is_bool($value['enabled'] ?? null) || ! is_bool($value['paused'] ?? null)) {
            return null;
        }

        return [
            'enabled' => $value['enabled'],
            'paused' => $value['paused'],
            'last_checked_at' => is_string($value['last_checked_at'] ?? null) ? $value['last_checked_at'] : null,
            'last_result' => is_string($value['last_result'] ?? null) ? $value['last_result'] : null,
        ];
    }
}
