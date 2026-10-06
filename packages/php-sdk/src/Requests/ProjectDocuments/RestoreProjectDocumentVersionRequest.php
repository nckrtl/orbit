<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\ProjectDocuments;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\ProjectDocuments\ProjectDocumentResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class RestoreProjectDocumentVersionRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        private readonly int $projectId,
        private readonly int $entryId,
        private readonly int $expectedRevision,
        private readonly int $versionId
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/projects/{$this->projectId}/documents/{$this->entryId}/restore-version";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): ProjectDocumentResponse
    {
        $data = $this->unwrapData($response);

        return ProjectDocumentResponse::fromGatewayData($data, $this->successRequestId($response));
    }

    /** @return array<string, mixed> */
    protected function defaultBody(): array
    {
        return [
            'expected_revision' => $this->expectedRevision,
            'version_id' => $this->versionId,
        ];
    }
}
