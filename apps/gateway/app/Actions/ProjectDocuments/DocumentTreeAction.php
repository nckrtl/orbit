<?php

declare(strict_types=1);

namespace App\Actions\ProjectDocuments;

use App\Domain\Shared\ResourceOperationException;
use App\Models\Project;
use App\Models\ProjectDocumentCleanup;
use App\Models\ProjectDocumentEntry;
use App\Models\ProjectDocumentUpload;
use App\Models\ProjectDocumentVersion;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Normalizer;

final readonly class DocumentTreeAction
{
    /** Acquire before reading any mutable document state, within a transaction. Also takes SQLite's writer lock. */
    public function lock(): void
    {
        DB::table('project_document_storages')->where('id', 1)->update(['id' => DB::raw('id')]);
    }

    public function entry(int $projectId, int $entryId): ProjectDocumentEntry
    {
        return ProjectDocumentEntry::query()->where('project_id', $projectId)->find($entryId)
            ?? throw new ResourceOperationException('project_documents.not_found', 'Document was not found.', 404);
    }

    public function assertRevision(ProjectDocumentEntry $entry, int $expectedRevision): void
    {
        if ($expectedRevision < 1) {
            throw ValidationException::withMessages(['expected_revision' => 'A positive revision is required.']);
        }
        if ($entry->revision !== $expectedRevision) {
            throw new ResourceOperationException('project_documents.revision_conflict', 'Document has changed.', 409,
                details: ['entry_id' => $entry->id, 'current_revision' => $entry->revision]);
        }
    }

    public function isArchived(ProjectDocumentEntry $entry): bool
    {
        do {
            if ($entry->archived_at !== null) {
                return true;
            }
            $entry = $entry->parent_id === null ? null : $this->entry($entry->project_id, $entry->parent_id);
        } while ($entry !== null);

        return false;
    }

    public function assertActive(ProjectDocumentEntry $entry): void
    {
        if ($this->isArchived($entry)) {
            throw new ResourceOperationException('project_documents.archived', 'Document or parent is archived.', 409);
        }
    }

    public function path(ProjectDocumentEntry $entry): string
    {
        $parts = [$entry->name];
        while ($entry->parent_id !== null) {
            $entry = $this->entry($entry->project_id, $entry->parent_id);
            array_unshift($parts, $entry->name);
        }

        return implode('/', $parts);
    }

    public function folder(int $projectId, string $name, ?int $parentId = null): ProjectDocumentEntry
    {
        return DB::transaction(function () use ($projectId, $name, $parentId): ProjectDocumentEntry {
            $this->lock();

            return $this->create($projectId, 'folder', $name, $parentId);
        });
    }

    /** Caller holds the document lock; file creation is only committed together with its first version. */
    public function create(int $projectId, string $kind, string $name, ?int $parentId): ProjectDocumentEntry
    {
        Project::query()->findOrFail($projectId);
        $name = $this->name($name);
        $this->destination($projectId, $parentId, 1);
        $this->uniqueName($projectId, $parentId, $name);

        return ProjectDocumentEntry::query()->create([
            'project_id' => $projectId, 'parent_id' => $parentId, 'sibling_scope' => $parentId ?? 0,
            'name' => $name, 'kind' => $kind, 'revision' => 1,
        ]);
    }

    /** @param array{name?: string, parent_id?: int|null} $changes */
    public function update(int $projectId, int $entryId, int $expectedRevision, array $changes): ProjectDocumentEntry
    {
        return DB::transaction(function () use ($projectId, $entryId, $expectedRevision, $changes): ProjectDocumentEntry {
            $this->lock();
            $entry = $this->entry($projectId, $entryId);
            $this->assertRevision($entry, $expectedRevision);
            $this->assertActive($entry);
            if ($changes === [] || array_diff(array_keys($changes), ['name', 'parent_id']) !== []) {
                throw ValidationException::withMessages(['entry' => 'Supply only name or parent_id.']);
            }
            $name = isset($changes['name']) ? $this->name($changes['name']) : $entry->name;
            $parent = array_key_exists('parent_id', $changes) ? $changes['parent_id'] : $entry->parent_id;
            $subtree = $this->subtree($entry);
            if ($parent !== null && in_array($parent, array_keys($subtree), true)) {
                throw ValidationException::withMessages(['parent_id' => 'A document cannot move into its own subtree.']);
            }
            $this->destination($projectId, $parent, max($subtree));
            $this->uniqueName($projectId, $parent, $name, $entryId);
            if ($name !== $entry->name || $parent !== $entry->parent_id) {
                $entry->update(['name' => $name, 'parent_id' => $parent, 'sibling_scope' => $parent ?? 0, 'revision' => $entry->revision + 1]);
            }

            return $entry;
        });
    }

    public function archive(int $projectId, int $entryId, int $expectedRevision, bool $archived): ProjectDocumentEntry
    {
        return DB::transaction(function () use ($projectId, $entryId, $expectedRevision, $archived): ProjectDocumentEntry {
            $this->lock();
            $entry = $this->entry($projectId, $entryId);
            $this->assertRevision($entry, $expectedRevision);
            if (! $archived && $entry->parent_id !== null) {
                $this->assertActive($this->entry($projectId, $entry->parent_id));
            }
            if (($entry->archived_at !== null) !== $archived) {
                $entry->update(['archived_at' => $archived ? now() : null, 'revision' => $entry->revision + 1]);
            }

            return $entry;
        });
    }

    public function remove(int $projectId, int $entryId, int $expectedRevision, bool $recursive = false): void
    {
        DB::transaction(function () use ($projectId, $entryId, $expectedRevision, $recursive): void {
            $this->lock();
            $entry = $this->entry($projectId, $entryId);
            $this->assertRevision($entry, $expectedRevision);
            $subtree = $this->subtree($entry);
            if (count($subtree) > 1 && ! $recursive) {
                throw new ResourceOperationException('project_documents.folder_not_empty', 'Folder is not empty.', 409);
            }
            $this->removeEntries(array_keys($subtree));
        });
    }

    /** Called inside the Project removal transaction, before deleting the Project. */
    public function removeProject(int $projectId): void
    {
        $this->lock();
        $this->removeEntries(array_map(ValidatedData::integer(...), ProjectDocumentEntry::query()->where('project_id', $projectId)->pluck('id')->all()));
        foreach (ProjectDocumentUpload::query()->where('project_id', $projectId)->where('state', 'active')->get() as $upload) {
            $this->abandon($upload);
        }
    }

    public function abandon(ProjectDocumentUpload $upload): void
    {
        if ($upload->state === 'published') {
            return;
        }
        $upload->update(['state' => 'abandoned']);
        ProjectDocumentCleanup::query()->updateOrCreate(['storage_key' => $upload->storage_key],
            ['retained_fence' => true, 'pending' => true, 'next_attempt_at' => now()]);
    }

    /** @param array<array-key, int> $ids */
    private function removeEntries(array $ids): void
    {
        foreach (ProjectDocumentVersion::query()->whereIn('entry_id', $ids)->get() as $version) {
            ProjectDocumentCleanup::query()->firstOrCreate(['storage_key' => $version->storage_key], ['next_attempt_at' => now()]);
        }
        foreach (ProjectDocumentUpload::query()->whereIn('entry_id', $ids)->where('state', 'active')->get() as $upload) {
            $this->abandon($upload);
        }
        ProjectDocumentEntry::query()->whereIn('id', $ids)->update(['parent_id' => null]);
        ProjectDocumentEntry::query()->whereIn('id', $ids)->delete();
    }

    /** @return non-empty-array<int, int> IDs mapped to their depth within the subtree. */
    private function subtree(ProjectDocumentEntry $entry): array
    {
        $depths = [$entry->id => 1];
        $frontier = [$entry->id];
        $depth = 1;
        while ($frontier !== []) {
            $frontier = array_map(ValidatedData::integer(...), ProjectDocumentEntry::query()->where('project_id', $entry->project_id)->whereIn('parent_id', $frontier)->pluck('id')->all());
            $depth++;
            foreach ($frontier as $id) {
                $depths[$id] = $depth;
            }
        }

        return $depths;
    }

    public function destination(int $projectId, ?int $parentId, int $subtreeDepth = 1): void
    {
        $depth = $subtreeDepth;
        while ($parentId !== null) {
            $parent = $this->entry($projectId, $parentId);
            if ($parent->kind !== 'folder') {
                throw ValidationException::withMessages(['parent_id' => 'The parent must be a folder.']);
            }
            $this->assertActive($parent);
            $parentId = $parent->parent_id;
            $depth++;
        }
        if ($depth > 32) {
            throw ValidationException::withMessages(['parent_id' => 'The document tree cannot exceed 32 entries in depth.']);
        }
    }

    public function name(string $name): string
    {
        $normalized = Normalizer::normalize($name);
        if ($normalized === false || strlen($normalized) < 1 || strlen($normalized) > 255
            || preg_match('~[\\\\/\p{C}]|^\s|\s$~u', $normalized) !== 0 || in_array($normalized, ['.', '..'], true)) {
            throw ValidationException::withMessages(['name' => 'Use a name without paths, controls, or surrounding whitespace.']);
        }

        return $normalized;
    }

    private function uniqueName(int $projectId, ?int $parentId, string $name, ?int $except = null): void
    {
        if (ProjectDocumentEntry::query()->where('project_id', $projectId)->where('sibling_scope', $parentId ?? 0)
            ->where('name', $name)->when($except !== null, fn ($query) => $query->where('id', '!=', $except))->exists()) {
            throw new ResourceOperationException('project_documents.name_conflict', 'A sibling reserves this name.', 409);
        }
    }
}
