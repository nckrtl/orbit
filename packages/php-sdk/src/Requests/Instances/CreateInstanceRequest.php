<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Instances;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Instances\InstanceResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

/** @mago-expect lint:excessive-parameter-list */
final class CreateInstanceRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        private readonly int $appId,
        private readonly int $nodeId,
        private readonly string $name,
        private readonly ?string $environment = null,
        private readonly string $documentRoot = 'public',
        private readonly string $phpVersion = '8.5',
        private readonly ?string $hostname = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/instances';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): InstanceResponse
    {
        $data = $this->unwrapData($response);
        $requestId = $this->successRequestId($response);

        return InstanceResponse::fromGatewayData($data, $requestId);
    }

    /** @return array<string, int|string> */
    protected function defaultBody(): array
    {
        $body = [
            'app_id' => $this->appId,
            'node_id' => $this->nodeId,
            'name' => $this->name,
            'document_root' => $this->documentRoot,
            'php_version' => $this->phpVersion,
        ];

        if ($this->environment !== null) {
            $body['environment'] = $this->environment;
        }

        if ($this->hostname !== null) {
            $body['hostname'] = $this->hostname;
        }

        return $body;
    }
}
