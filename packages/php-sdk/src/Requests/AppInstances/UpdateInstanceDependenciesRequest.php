<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\AppInstances;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Dependencies\InstanceDependencyUpdateResponse;
use Orbit\Sdk\Support\DependencyInventoryDecoder;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Repositories\Body\JsonBodyRepository;
use Saloon\Traits\Body\HasJsonBody;
use SensitiveParameter;

final class UpdateInstanceDependenciesRequest extends GatewayRequest implements HasBody
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

    public function __construct(private readonly int $instanceId) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/instances/{$this->instanceId}/dependencies/update";
    }

    public function hasRequestFailed(#[SensitiveParameter] Response $response): ?bool
    {
        DependencyInventoryDecoder::guardBody($response->body(), $response->header('X-Orbit-Request-Id'), update: true);

        return parent::hasRequestFailed($response);
    }

    public function createDtoFromResponse(#[SensitiveParameter] Response $response): InstanceDependencyUpdateResponse
    {
        return DependencyInventoryDecoder::decodeUpdate(
            $response, $this->instanceId,
        );
    }
}
