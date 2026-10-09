<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Routes;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Routes\RouteResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class CreateRouteRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        private readonly string $domain,
        private readonly string $publication = 'private',
        private readonly ?int $instanceId = null,
        private readonly ?int $nodeId = null,
        private readonly ?string $upstream = null,
        private readonly ?int $processId = null,
        private readonly ?string $webRoot = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/routes';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): RouteResponse
    {
        return RouteResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }

    /** @return array<string, int|string> */
    protected function defaultBody(): array
    {
        return array_filter(
            [
                'domain' => $this->domain,
                'publication' => $this->publication,
                'instance_id' => $this->instanceId,
                'node_id' => $this->nodeId,
                'upstream' => $this->upstream,
                'process_id' => $this->processId,
                'web_root' => $this->webRoot,
            ],
            static fn (int|string|null $value): bool => $value !== null,
        );
    }
}
