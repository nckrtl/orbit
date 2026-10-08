<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\ProjectDocuments;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\ProjectDocuments\ProjectDocumentStorageResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class UpdateProjectDocumentStorageRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::PUT;

    public function __construct(
        private readonly ?string $endpoint = null,
        private readonly ?string $region = null,
        private readonly ?string $bucket = null,
        #[\SensitiveParameter] private readonly ?string $accessKeyId = null,
        #[\SensitiveParameter] private readonly ?string $secretAccessKey = null
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/project-document-storage';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): ProjectDocumentStorageResponse
    {
        $data = $this->unwrapData($response);

        return ProjectDocumentStorageResponse::fromGatewayData($data, $this->successRequestId($response));
    }

    /** @return array<string, mixed> */
    protected function defaultBody(): array
    {
        return array_filter([
            'endpoint' => $this->endpoint,
            'region' => $this->region,
            'bucket' => $this->bucket,
            'access_key_id' => $this->accessKeyId,
            'secret_access_key' => $this->secretAccessKey,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
