<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\AppInstances;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\AppInstances\AppInstanceResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class CreateAppInstanceRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        private readonly int $appId,
        private readonly int $nodeId,
        private readonly string $name,
        private readonly ?string $root = null,
        private readonly ?string $hostname = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/instances';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): AppInstanceResponse
    {
        return AppInstanceResponse::fromGatewayData(
            $this->unwrapData($response),
            $this->successRequestId($response),
        );
    }

    /** @return array<string, int|string> */
    protected function defaultBody(): array
    {
        $body = [
            'app_id' => $this->appId,
            'node_id' => $this->nodeId,
            'name' => $this->name,
        ];

        if ($this->root !== null) {
            $body['root'] = $this->root;
        }

        if ($this->hostname !== null) {
            $body['hostname'] = $this->hostname;
        }

        return $body;
    }
}
