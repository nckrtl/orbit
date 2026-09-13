<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\AppInstances;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\AppInstances\AppInstanceResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Repositories\Body\JsonBodyRepository;
use Saloon\Traits\Body\HasJsonBody;

final class AppInstanceDeploymentLayoutRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody {
        body as private jsonBody;
    }

    public function body(): JsonBodyRepository
    {
        return $this->jsonBody()->setJsonFlags(JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT);
    }

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        private readonly int $appInstanceId,
        #[\SensitiveParameter]
        private readonly ?string $sqliteSourcePath = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/instances/{$this->appInstanceId}/deployment-layout";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): AppInstanceResponse
    {
        return AppInstanceResponse::fromGatewayData(
            $this->unwrapData($response),
            $this->successRequestId($response),
        );
    }

    /** @return array{sqlite_source_path?: string} */
    protected function defaultBody(): array
    {
        return $this->sqliteSourcePath === null ? [] : ['sqlite_source_path' => $this->sqliteSourcePath];
    }
}
