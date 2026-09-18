<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\AppInstances;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\AppInstances\ResolvedDirectoryInstanceResponse;
use Orbit\Sdk\Support\InstanceResolutionDecoder;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use SensitiveParameter;

final class ResolveDirectoryInstanceRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(#[SensitiveParameter] private readonly string $directory) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/instances/resolve-directory';
    }

    /** @return array<string, string> */
    protected function defaultQuery(): array
    {
        return ['directory' => $this->directory];
    }

    public function hasRequestFailed(#[SensitiveParameter] Response $response): ?bool
    {
        InstanceResolutionDecoder::guardBody($response->body(), $response->header('X-Orbit-Request-Id'));

        return parent::hasRequestFailed($response);
    }

    public function createDtoFromResponse(#[SensitiveParameter] Response $response): ResolvedDirectoryInstanceResponse
    {
        return InstanceResolutionDecoder::decodeDirectoryFromResponse($response, $this->successRequestId($response));
    }
}
