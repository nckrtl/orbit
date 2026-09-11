<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Apps;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Apps\AppRuntimeDefinitionResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasStringBody;
use SensitiveParameter;

final class CreateScheduleDefinitionRequest extends GatewayRequest implements HasBody
{
    use HasStringBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        private readonly int $appId,
        #[SensitiveParameter]
        private readonly string $definition,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/apps/{$this->appId}/schedule-definitions";
    }

    protected function defaultHeaders(): array
    {
        return ['Content-Type' => 'application/json'];
    }

    public function createDtoFromResponse(#[SensitiveParameter] Response $response): AppRuntimeDefinitionResponse
    {
        return AppRuntimeDefinitionResponse::fromGatewayData(
            $this->unwrapData($response),
            $this->successRequestId($response),
        );
    }

    protected function defaultBody(): string
    {
        return $this->definition;
    }
}
