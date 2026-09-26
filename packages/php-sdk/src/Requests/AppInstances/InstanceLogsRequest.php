<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\AppInstances;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\AppInstances\InstanceLogsResponse;
use Orbit\Sdk\Support\CredentialRedactor;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class InstanceLogsRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        private readonly int $instanceId,
        private readonly int $lines = 100,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/instances/{$this->instanceId}/logs";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): InstanceLogsResponse
    {
        $data = $this->unwrapData($response);
        $redactor = new CredentialRedactor;

        return new InstanceLogsResponse(
            id: is_int($data['id'] ?? null) ? $data['id'] : 0,
            name: is_string($data['name'] ?? null) ? $data['name'] : '',
            lines: is_int($data['lines'] ?? null) ? $data['lines'] : 0,
            logs: is_string($data['logs'] ?? null) ? $redactor->redactText($data['logs']) : '',
            requestId: $this->successRequestId($response),
        );
    }

    /** @return array{lines: int} */
    protected function defaultQuery(): array
    {
        return ['lines' => $this->lines];
    }
}
