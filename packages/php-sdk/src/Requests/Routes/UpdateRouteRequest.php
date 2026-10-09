<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Routes;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Routes\RouteResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class UpdateRouteRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::PATCH;

    public function __construct(
        private readonly int $routeId,
        private readonly ?string $domain = null,
        private readonly ?string $publication = null,
        private readonly ?string $webRoot = null,
        private readonly bool $clearWebRoot = false,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/routes/{$this->routeId}";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): RouteResponse
    {
        return RouteResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }

    /** @return array<string, string|null> */
    protected function defaultBody(): array
    {
        $body = array_filter(
            [
                'domain' => $this->domain,
                'publication' => $this->publication,
                'web_root' => $this->webRoot,
            ],
            static fn (?string $value): bool => $value !== null,
        );

        return $this->clearWebRoot ? [...$body, 'web_root' => null] : $body;
    }
}
