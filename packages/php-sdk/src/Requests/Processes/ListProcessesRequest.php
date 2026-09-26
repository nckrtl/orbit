<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Processes;

use InvalidArgumentException;
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
        if ($this->target === null) {
            return [];
        }

        $query = $this->target->toRequestData();
        $targetId = $query['target_id'];

        // List filtering accepts only the integer id the Gateway validates. A domain
        // selector is representable on the shared target, and create still sends it,
        // but it is not an integer query value.
        if (! is_int($targetId)) {
            throw new InvalidArgumentException('Process list target id must be an integer.');
        }

        return [
            'target_type' => $query['target_type'],
            'target_id' => $targetId,
        ];
    }
}
