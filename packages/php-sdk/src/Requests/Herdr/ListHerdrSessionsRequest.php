<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Herdr;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Herdr\HerdrSessionResponse;
use Orbit\Sdk\Responses\Herdr\HerdrSessionsResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ListHerdrSessionsRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        private readonly int $nodeId,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/herdr/sessions';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): HerdrSessionsResponse
    {
        $requestId = $this->successRequestId($response);
        $sessions = [];

        foreach ($this->unwrapDataList($response) as $data) {
            $sessions[] = HerdrSessionResponse::fromGatewayData($data, $requestId);
        }

        return new HerdrSessionsResponse($sessions, $requestId);
    }

    /** @return array{node_id: int} */
    protected function defaultQuery(): array
    {
        return ['node_id' => $this->nodeId];
    }
}
