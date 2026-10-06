<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\ProjectDocuments;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\ProjectDocuments\ProjectDocumentsResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class ListProjectDocumentsRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        private readonly int $projectId,
        private readonly ?int $parentId = null,
        private readonly ?string $state = null,
        private readonly ?string $kind = null,
        private readonly ?string $cursor = null,
        private readonly ?int $limit = null
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/projects/{$this->projectId}/documents";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): ProjectDocumentsResponse
    {
        $data = $this->unwrapDataList($response);

        return ProjectDocumentsResponse::fromGatewayData($data, $this->successRequestId($response), is_string($response->json('meta.next_cursor')) ? $response->json('meta.next_cursor') : null);
    }

    /** @return array<string, mixed> */
    protected function defaultQuery(): array
    {
        return array_filter([
            'parent_id' => $this->parentId,
            'state' => $this->state,
            'kind' => $this->kind,
            'cursor' => $this->cursor,
            'limit' => $this->limit,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
