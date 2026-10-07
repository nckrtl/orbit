<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Fleet;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Fleet\FleetRolloutStatusResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class ResumeFleetRolloutRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    /** @param string|null $skip  The Node name or ID to skip, or null to visit the failed Node again. */
    public function __construct(private readonly ?string $skip = null) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/fleet/rollout/resume';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): FleetRolloutStatusResponse
    {
        return FleetRolloutStatusResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }

    /** @return array{skip?: string} */
    protected function defaultBody(): array
    {
        return $this->skip === null ? [] : ['skip' => $this->skip];
    }
}
