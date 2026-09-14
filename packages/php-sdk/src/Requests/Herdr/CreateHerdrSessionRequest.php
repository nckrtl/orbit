<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Herdr;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Herdr\HerdrSessionResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class CreateHerdrSessionRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        private readonly int $nodeId,
        private readonly string $session,
        private readonly string $user,
        private readonly bool $publishObserver = false,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/herdr/sessions';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): HerdrSessionResponse
    {
        return HerdrSessionResponse::fromGatewayData(
            $this->unwrapData($response),
            $this->successRequestId($response),
        );
    }

    /** @return array{node_id: int, session: string, user: string, publish_observer: bool} */
    protected function defaultBody(): array
    {
        return [
            'node_id' => $this->nodeId,
            'session' => $this->session,
            'user' => $this->user,
            'publish_observer' => $this->publishObserver,
        ];
    }
}
