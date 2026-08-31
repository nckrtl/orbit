<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Clusters;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Clusters\ClusterResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class DetachClusterNodeRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::DELETE;

    public function __construct(
        private readonly int $clusterId,
        private readonly int $nodeId,
        private readonly bool $force,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/clusters/{$this->clusterId}/nodes/{$this->nodeId}";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): ClusterResponse
    {
        return ClusterResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }

    /** @return array{force: bool} */
    protected function defaultBody(): array
    {
        return ['force' => $this->force];
    }
}
