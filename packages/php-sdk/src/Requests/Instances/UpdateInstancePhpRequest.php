<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Instances;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Instances\InstanceResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class UpdateInstancePhpRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::PATCH;

    public function __construct(
        private readonly int $instanceId,
        private readonly string $phpVersion,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/instances/{$this->instanceId}/php";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): InstanceResponse
    {
        $data = $this->unwrapData($response);
        $requestId = $this->successRequestId($response);

        return InstanceResponse::fromGatewayData($data, $requestId);
    }

    /** @return array{php_version: string} */
    protected function defaultBody(): array
    {
        return ['php_version' => $this->phpVersion];
    }
}
