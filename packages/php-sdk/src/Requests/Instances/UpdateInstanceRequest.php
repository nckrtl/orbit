<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Instances;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Instances\InstanceResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class UpdateInstanceRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::PATCH;

    public function __construct(
        private readonly int $instanceId,
        private readonly string $branch,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/instances/{$this->instanceId}";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): InstanceResponse
    {
        return InstanceResponse::fromGatewayData(
            $this->unwrapData($response),
            $this->successRequestId($response),
        );
    }

    /** @return array{branch: string} */
    protected function defaultBody(): array
    {
        return ['branch' => $this->branch];
    }
}
