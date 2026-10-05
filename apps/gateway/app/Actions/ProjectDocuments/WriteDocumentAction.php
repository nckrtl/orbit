<?php

declare(strict_types=1);

namespace App\Actions\ProjectDocuments;

use App\Data\ProjectDocuments\DocumentBody;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\ProjectDocuments\DocumentBodies;
use App\Models\Project;
use App\Models\ProjectDocumentEntry;
use App\Models\ProjectDocumentStorage;
use App\Models\ProjectDocumentUpload;
use App\Models\ProjectDocumentVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;
use Throwable;

final readonly class WriteDocumentAction
{
    public function __construct(private DocumentTreeAction $tree, private DocumentBodies $bodies) {}

    public function create(int $projectId, string $name, ?int $parentId, #[SensitiveParameter] DocumentBody $body, ?int $authorNodeId): ProjectDocumentEntry
    {
        return $this->store($projectId, null, null, $name, $parentId, $body, $authorNodeId);
    }

    public function write(int $projectId, int $entryId, int $expectedRevision, #[SensitiveParameter] DocumentBody $body, ?int $authorNodeId): ProjectDocumentEntry
    {
        return $this->store($projectId, $entryId, $expectedRevision, null, null, $body, $authorNodeId);
    }

    public function read(int $projectId, int $entryId, ?int $versionId = null): DocumentBody
    {
        $entry = $this->tree->entry($projectId, $entryId);
        $version = $this->version($entry, $versionId);

        return new DocumentBody($this->bodies->get($version->storage_key, $version->size_bytes, $version->sha256), $version->media_type);
    }

    public function restoreVersion(int $projectId, int $entryId, int $expectedRevision, int $versionId, ?int $authorNodeId): ProjectDocumentEntry
    {
        $entry = $this->tree->entry($projectId, $entryId);
        $this->tree->assertRevision($entry, $expectedRevision);
        $this->tree->assertActive($entry);

        return $this->write($projectId, $entryId, $expectedRevision, $this->read($projectId, $entryId, $versionId), $authorNodeId);
    }

    public function version(ProjectDocumentEntry $entry, ?int $versionId = null): ProjectDocumentVersion
    {
        if ($entry->kind !== 'file') {
            throw new ResourceOperationException('project_documents.not_file', 'Document is not a file.', 422);
        }

        return $entry->versions()->find($versionId ?? $entry->current_version_id)
            ?? throw new ResourceOperationException('project_documents.not_found', 'Document version was not found.', 404);
    }

    private function store(int $projectId, ?int $entryId, ?int $revision, ?string $name, ?int $parentId, #[SensitiveParameter] DocumentBody $body, ?int $authorNodeId): ProjectDocumentEntry
    {
        $this->bodies->assertOutsideTransaction();
        $prepared = DB::transaction(function () use ($projectId, $entryId, $revision, $name, $parentId, $body): ProjectDocumentUpload|ProjectDocumentEntry {
            $this->tree->lock();
            Project::query()->findOrFail($projectId);
            if ($entryId !== null) {
                $entry = $this->tree->entry($projectId, $entryId);
                $this->tree->assertRevision($entry, $revision ?? 0);
                $this->tree->assertActive($entry);
                $version = $this->version($entry);
                if ($version->sha256 === $body->sha256 && $version->media_type === $body->mediaType && $version->size_bytes === $body->sizeBytes) {
                    return $entry;
                }
            } else {
                $this->tree->name($name ?? '');
                $this->tree->destination($projectId, $parentId);
            }
            if (ProjectDocumentStorage::query()->findOrFail(1)->endpoint === null) {
                throw new ResourceOperationException('project_documents.storage_not_configured', 'Document storage is not configured.', 409);
            }

            return ProjectDocumentUpload::query()->create([
                'project_id' => $projectId, 'entry_id' => $entryId,
                'storage_key' => 'orbit-documents/'.$projectId.'/'.($entryId ?? 'new').'/'.Str::uuid(),
            ]);
        });
        if ($prepared instanceof ProjectDocumentEntry) {
            return $prepared;
        }
        try {
            DB::transaction(function () use ($prepared): void {
                $this->tree->lock();
                $this->assertActiveIntent(ProjectDocumentUpload::query()->lockForUpdate()->findOrFail($prepared->id));
            });
            $this->bodies->put($prepared->storage_key, $body);

            return DB::transaction(function () use ($prepared, $projectId, $entryId, $revision, $name, $parentId, $body, $authorNodeId): ProjectDocumentEntry {
                $this->tree->lock();
                $intent = ProjectDocumentUpload::query()->lockForUpdate()->findOrFail($prepared->id);
                $this->assertActiveIntent($intent);
                if ($entryId === null) {
                    $entry = $this->tree->create($projectId, 'file', $name ?? '', $parentId);
                    $number = 1;
                } else {
                    $entry = $this->tree->entry($projectId, $entryId);
                    $this->tree->assertRevision($entry, $revision ?? 0);
                    $this->tree->assertActive($entry);
                    $number = $this->version($entry)->number + 1;
                }
                $version = ProjectDocumentVersion::query()->create([
                    'entry_id' => $entry->id, 'upload_id' => $intent->id, 'number' => $number,
                    'media_type' => $body->mediaType, 'size_bytes' => $body->sizeBytes, 'sha256' => $body->sha256,
                    'storage_key' => $intent->storage_key, 'created_by_node_id' => $authorNodeId,
                ]);
                $entry->update(['current_version_id' => $version->id, 'revision' => $entryId === null ? 1 : $entry->revision + 1]);
                $intent->update(['state' => 'published', 'entry_id' => $entry->id]);

                return $entry;
            });
        } catch (Throwable $exception) {
            try {
                DB::transaction(function () use ($prepared): void {
                    $this->tree->lock();
                    $this->tree->abandon(ProjectDocumentUpload::query()->lockForUpdate()->findOrFail($prepared->id));
                });
            } catch (Throwable) {
                // The durable active intent remains recoverable if recording abandonment itself fails.
                throw new ResourceOperationException('project_documents.storage_unavailable', 'Document publication is unavailable.', 503);
            }
            if ($exception instanceof ResourceOperationException || $exception instanceof ValidationException) {
                throw $exception;
            }
            throw new ResourceOperationException('project_documents.storage_unavailable', 'Document publication is unavailable.', 503);
        }
    }

    private function assertActiveIntent(ProjectDocumentUpload $intent): void
    {
        if ($intent->state !== 'active') {
            throw new ResourceOperationException('project_documents.upload_abandoned', 'Upload was abandoned; retry with a fresh upload.', 409);
        }
    }
}
