<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Tools;

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Tools\ToolInventoryResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ScanToolInventoryRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        private readonly int $nodeId,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/tool-inventory';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): ToolInventoryResponse
    {
        $data = $response->json('data');
        if (! $this->stringKeyedMap($data)) {
            throw new GatewayApiException('Gateway tool inventory contains invalid data.');
        }

        $managers = $data['managers'] ?? null;
        if (! is_array($managers) || ! array_is_list($managers)) {
            throw new GatewayApiException('Gateway tool inventory contains invalid data.');
        }

        foreach ($managers as $manager) {
            if (! $this->stringKeyedMap($manager)) {
                throw new GatewayApiException('Gateway tool inventory contains invalid data.');
            }

            $packages = $manager['packages'] ?? null;
            if (! is_array($packages) || ! array_is_list($packages)) {
                throw new GatewayApiException('Gateway tool inventory contains invalid data.');
            }

            foreach ($packages as $package) {
                if (! $this->stringKeyedMap($package)) {
                    throw new GatewayApiException('Gateway tool inventory contains invalid data.');
                }
            }
        }

        return ToolInventoryResponse::fromGatewayData($data, $this->successRequestId($response));
    }

    protected function defaultQuery(): array
    {
        return ['node_id' => $this->nodeId];
    }

    /** @phpstan-assert-if-true array<string, mixed> $value */
    private function stringKeyedMap(mixed $value): bool
    {
        return is_array($value)
            && ! array_is_list($value)
            && array_all(array_keys($value), static fn (int|string $key): bool => is_string($key));
    }
}
