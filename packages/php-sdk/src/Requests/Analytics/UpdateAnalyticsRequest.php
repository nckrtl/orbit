<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Analytics;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Analytics\AnalyticsUpdateResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;
use SensitiveParameter;

/** Pins another Plausible version for the analytics role and replaces its `plausible` Process. */
final class UpdateAnalyticsRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(private readonly string $version) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/analytics/update';
    }

    public function createDtoFromResponse(#[SensitiveParameter] Response $response): AnalyticsUpdateResponse
    {
        return AnalyticsUpdateResponse::fromGatewayData(
            $this->unwrapData($response),
            $this->successRequestId($response),
        );
    }

    /** @return array{version: string} */
    protected function defaultBody(): array
    {
        return ['version' => $this->version];
    }
}
