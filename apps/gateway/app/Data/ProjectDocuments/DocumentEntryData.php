<?php

declare(strict_types=1);

namespace App\Data\ProjectDocuments;

use App\Actions\ProjectDocuments\DocumentTreeAction;
use App\Models\ProjectDocumentEntry;
use App\Models\ProjectDocumentVersion;
use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
final class DocumentEntryData extends Data
{
    public function __construct(public int $id, public int $projectId, public string $kind, public ?int $parentId, public string $name, public int $revision, public ?string $archivedAt, public string $createdAt, public string $updatedAt, public string $path, public bool $isArchived, public ?DocumentVersionData $currentVersion) {}

    public static function fromModel(ProjectDocumentEntry $entry): self
    {
        $tree = app(DocumentTreeAction::class);
        $version = $entry->current_version_id === null ? null : ProjectDocumentVersion::query()->findOrFail($entry->current_version_id);

        return new self($entry->id, $entry->project_id, $entry->kind, $entry->parent_id, $entry->name, $entry->revision, $entry->archived_at?->toISOString(), $entry->created_at?->toISOString() ?? '', $entry->updated_at?->toISOString() ?? '', $tree->path($entry), $tree->isArchived($entry), $version === null ? null : DocumentVersionData::fromModel($version));
    }
}
