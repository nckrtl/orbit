<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Clusters;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Clusters\ClusterResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class UpdateClusterRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::PATCH;

    /** @mago-expect lint:excessive-parameter-list Each mutable field needs an explicit omission flag. */
    public function __construct(
        private readonly int $clusterId,
        private readonly bool $hasName = false,
        private readonly ?string $name = null,
        private readonly bool $hasTld = false,
        private readonly ?string $tld = null,
        private readonly bool $hasState = false,
        private readonly ?string $state = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/clusters/{$this->clusterId}";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): ClusterResponse
    {
        return ClusterResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }

    /** @return array<string, string|null> */
    protected function defaultBody(): array
    {
        $body = [];

        if ($this->hasName) {
            $body['name'] = $this->name;
        }

        if ($this->hasTld) {
            $body['tld'] = $this->tld;
        }

        if ($this->hasState) {
            $body['state'] = $this->state;
        }

        return $body;
    }
}
