<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Instances;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Instances\InstanceRemovalResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class DestroyInstanceRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::DELETE;

    public function __construct(
        private readonly int $instanceId,
        private readonly ?bool $force = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/instances/{$this->instanceId}";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): InstanceRemovalResponse
    {
        return InstanceRemovalResponse::fromGatewayData(
            $this->unwrapData($response),
            $this->successRequestId($response),
        );
    }

    /** @return array{force?: bool} */
    protected function defaultBody(): array
    {
        return $this->force === null ? [] : ['force' => $this->force];
    }
}
