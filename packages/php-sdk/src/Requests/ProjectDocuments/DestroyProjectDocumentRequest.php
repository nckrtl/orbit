<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\ProjectDocuments;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\ProjectDocuments\RemovedProjectDocumentResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class DestroyProjectDocumentRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::DELETE;

    public function __construct(
        private readonly int $projectId,
        private readonly int $entryId,
        private readonly int $expectedRevision,
        private readonly bool $recursive = false
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/projects/{$this->projectId}/documents/{$this->entryId}";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): RemovedProjectDocumentResponse
    {
        $data = $this->unwrapData($response);

        return RemovedProjectDocumentResponse::fromGatewayData($data, $this->successRequestId($response));
    }

    /** @return array<string, mixed> */
    protected function defaultBody(): array
    {
        return [
            'expected_revision' => $this->expectedRevision,
            'recursive' => $this->recursive,
        ];
    }
}
