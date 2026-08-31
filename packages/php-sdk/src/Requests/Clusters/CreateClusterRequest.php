<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Clusters;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Clusters\ClusterResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class CreateClusterRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        private readonly string $name,
        private readonly ?string $tld = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/clusters';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): ClusterResponse
    {
        return ClusterResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }

    /** @return array<string, string> */
    protected function defaultBody(): array
    {
        return array_filter(
            [
                'name' => $this->name,
                'tld' => $this->tld,
            ],
            static fn (?string $value): bool => $value !== null,
        );
    }
}
