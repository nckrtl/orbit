<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\ProjectDocuments;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\ProjectDocuments\ProjectDocumentResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class UpdateProjectDocumentRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::PATCH;

    public function __construct(
        private readonly int $projectId,
        private readonly int $entryId,
        private readonly int $expectedRevision,
        private readonly ?string $name = null,
        private readonly ?int $parentId = null,
        private readonly bool $parentProvided = false
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/projects/{$this->projectId}/documents/{$this->entryId}";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): ProjectDocumentResponse
    {
        $data = $this->unwrapData($response);

        return ProjectDocumentResponse::fromGatewayData($data, $this->successRequestId($response));
    }

    /** @return array<string, mixed> */
    protected function defaultBody(): array
    {
        return array_filter([
            'expected_revision' => $this->expectedRevision,
            'name' => $this->name,
            ...($this->parentProvided || $this->parentId !== null ? ['parent_id' => $this->parentId] : []),
        ], static fn (mixed $value, string $key): bool => $key === 'parent_id' || $value !== null, ARRAY_FILTER_USE_BOTH);
    }
}
