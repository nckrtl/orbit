<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\ProjectDocuments;

final readonly class ProjectDocumentsResponse
{
    /** @param list<ProjectDocumentResponse> $entries */
    public function __construct(public array $entries, public string $requestId, public ?string $nextCursor) {}

    /** @param array<array-key, mixed> $data */
    public static function fromGatewayData(#[\SensitiveParameter] array $data, string $requestId, ?string $nextCursor): self
    {
        $items = [];
        foreach ($data as $row) {
            if (is_array($row)) {
                $items[] = ProjectDocumentResponse::fromGatewayData($row, $requestId);
            }
        }

        return new self($items, $requestId, $nextCursor);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['data' => array_map(static fn (ProjectDocumentResponse $item): array => $item->dataArray(), $this->entries), 'meta' => ['request_id' => $this->requestId, 'next_cursor' => $this->nextCursor]];
    }
}
