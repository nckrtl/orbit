<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Analytics;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Analytics\AnalyticsCredentialsResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;
use SensitiveParameter;

/** Stores a Plausible Stats API key. The key is never returned. */
final class SetAnalyticsCredentialsRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::PUT;

    public function __construct(
        #[SensitiveParameter]
        private readonly string $apiKey,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/analytics/credentials';
    }

    public function createDtoFromResponse(#[SensitiveParameter] Response $response): AnalyticsCredentialsResponse
    {
        return AnalyticsCredentialsResponse::fromGatewayData(
            $this->unwrapData($response),
            $this->successRequestId($response),
        );
    }

    /** @return array{api_key: string} */
    protected function defaultBody(): array
    {
        return ['api_key' => $this->apiKey];
    }

    /** @return array{type: class-string} */
    public function __debugInfo(): array
    {
        return ['type' => self::class];
    }
}
