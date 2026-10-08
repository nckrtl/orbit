<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\ProjectDocuments;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\ProjectDocuments\ProjectDocumentsResponse;
use Saloon\Enums\Method;
use Saloon\Http\Response;

final class SearchProjectDocumentsRequest extends GatewayRequest
{
    #[\Override]
    protected Method $method = Method::GET;

    public function __construct(
        private readonly int $projectId,
        private readonly string $q,
        private readonly ?string $state = null,
        private readonly ?string $kind = null,
        private readonly ?string $cursor = null,
        private readonly ?int $limit = null
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/projects/{$this->projectId}/documents/search";
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
            'q' => $this->q,
            'state' => $this->state,
            'kind' => $this->kind,
            'cursor' => $this->cursor,
            'limit' => $this->limit,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
