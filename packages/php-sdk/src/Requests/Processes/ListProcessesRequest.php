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

    public function __construct(
        private readonly string $targetType,
        private readonly int $targetId,
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

    /** @return array{target_type: string, target_id: int} */
    protected function defaultQuery(): array
    {
        return [
            'target_type' => $this->targetType,
            'target_id' => $this->targetId,
        ];
    }
}
