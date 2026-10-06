<?php

declare(strict_types=1);

namespace App\Data\ProjectDocuments;

use App\Models\ProjectDocumentVersion;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
final class DocumentVersionData extends Data
{
    public function __construct(public int $id, public int $number, public string $mediaType, public int $sizeBytes, public string $sha256, public string $createdAt, public ?int $createdByNodeId) {}

    public static function fromModel(ProjectDocumentVersion $version): self
    {
        return new self($version->id, $version->number, $version->media_type, $version->size_bytes, $version->sha256, $version->created_at->toISOString() ?? '', $version->created_by_node_id);
    }
}
