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

    protected Method $method = Method::POST;

    public function __construct(
        private readonly int $appId,
        private readonly int $nodeId,
        private readonly string $name,
        private readonly string $environment = 'development',
        private readonly string $documentRoot = 'public',
        private readonly string $phpVersion = '8.5',
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/instances';
    }

    public function createDtoFromResponse(Response $response): InstanceResponse
    {
        $data = $this->unwrapData($response);
        $meta = $this->unwrapMeta($response);
        $requestId = is_string($meta['request_id'] ?? null) ? $meta['request_id'] : '';

        return InstanceResponse::fromGatewayData($data, $requestId);
    }

    /** @return array<string, int|string> */
    protected function defaultBody(): array
    {
        return [
            'app_id' => $this->appId,
            'node_id' => $this->nodeId,
            'name' => $this->name,
            'environment' => $this->environment,
            'document_root' => $this->documentRoot,
            'php_version' => $this->phpVersion,
        ];
    }
}
