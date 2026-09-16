<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\DatabaseConnections;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\DatabaseConnections\DatabaseConnectionAttachmentResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Repositories\Body\JsonBodyRepository;
use Saloon\Traits\Body\HasJsonBody;

final class AddInstanceDatabaseRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody {
        body as private jsonBody;
    }

    public function body(): JsonBodyRepository
    {
        return $this->jsonBody()->setJsonFlags(JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT);
    }

    #[\Override]
    protected Method $method = Method::PUT;

    public function __construct(
        private readonly int|string $appInstance,
        private readonly string $slug,
        private readonly ?string $prefix = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/instances/'.rawurlencode((string) $this->appInstance)
            .'/database-connections/'.rawurlencode($this->slug);
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): DatabaseConnectionAttachmentResponse
    {
        return DatabaseConnectionAttachmentResponse::fromGatewayData(
            $this->unwrapData($response),
            $this->successRequestId($response),
        );
    }

    /** @return array<string, string> */
    protected function defaultBody(): array
    {
        return $this->prefix === null ? [] : ['prefix' => $this->prefix];
    }
}
