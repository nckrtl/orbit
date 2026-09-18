<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Processes;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Processes\ProcessesResponse;
use Orbit\Sdk\Responses\Processes\ProcessResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ListProcessesRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    /** @param  null|AppInstanceProcessTarget|NodeProcessTarget  $target  Null lists every Process in the fleet. */
    public function __construct(
        private readonly AppInstanceProcessTarget|NodeProcessTarget|null $target = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/processes';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): ProcessesResponse
    {
        $requestId = $this->successRequestId($response);
        $processes = [];

        foreach ($this->unwrapDataList($response) as $data) {
            $processes[] = ProcessResponse::fromGatewayData($data, $requestId);
        }

        return new ProcessesResponse($processes, $requestId);
    }

    /** @return array{target_type?: string, target_id?: int} */
    protected function defaultQuery(): array
    {
        return $this->target?->toRequestData() ?? [];
    }
}
