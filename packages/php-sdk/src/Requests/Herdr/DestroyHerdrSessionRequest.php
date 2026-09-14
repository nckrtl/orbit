<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Herdr;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Herdr\HerdrSessionResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class DestroyHerdrSessionRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::DELETE;

    public function __construct(
        private readonly int $sessionId,
        private readonly bool $acceptTermination = false,
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

    /** @return array{accept_termination: bool} */
    protected function defaultBody(): array
    {
        return ['accept_termination' => $this->acceptTermination];
    }
}
