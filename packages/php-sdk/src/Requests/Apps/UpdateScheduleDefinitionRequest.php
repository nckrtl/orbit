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

final class UpdateScheduleDefinitionRequest extends GatewayRequest implements HasBody
{
    use HasStringBody;

    #[\Override]
    protected Method $method = Method::PUT;

    public function __construct(
        private readonly int $appId,
        private readonly string $name,
        #[SensitiveParameter]
        private readonly string $definition,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/projects/{$this->appId}/schedule-definitions/".rawurlencode($this->name);
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
