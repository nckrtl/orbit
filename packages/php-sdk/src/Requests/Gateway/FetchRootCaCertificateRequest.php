<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Gateway;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Gateway\RootCaCertificateResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class FetchRootCaCertificateRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/api/v1/ca/root';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): RootCaCertificateResponse
    {
        $data = $this->unwrapData($response);

        return new RootCaCertificateResponse(
            certificate: is_string($data['root_ca'] ?? null) ? $data['root_ca'] : '',
            sha256: is_string($data['sha256'] ?? null) ? $data['sha256'] : '',
            requestId: $this->successRequestId($response),
        );
    }
}
