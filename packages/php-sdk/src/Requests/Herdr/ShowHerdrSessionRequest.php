<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Herdr;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Herdr\HerdrSessionResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ShowHerdrSessionRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        private readonly int $sessionId,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/herdr/sessions/{$this->sessionId}";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): HerdrSessionResponse
    {
        return HerdrSessionResponse::fromGatewayData(
            $this->unwrapData($response),
            $this->successRequestId($response),
        );
    }
}
