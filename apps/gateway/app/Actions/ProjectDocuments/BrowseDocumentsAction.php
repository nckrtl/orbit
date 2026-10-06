<?php

declare(strict_types=1);

namespace App\Actions\ProjectDocuments;

use App\Data\ProjectDocuments\DocumentEntryData;
use App\Data\ProjectDocuments\DocumentVersionData;
use App\Models\ProjectDocumentEntry;
use App\Support\ValidatedData;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use Throwable;

final readonly class BrowseDocumentsAction
{
    public function __construct(private DocumentTreeAction $tree, private WriteDocumentAction $writer) {}

    /** @return array{data: list<array<string, mixed>>, next_cursor: ?string} */
    public function entries(int $projectId, ?int $parentId, string $state, ?string $kind, ?string $query, ?string $cursor, int $limit): array
    {
        if ($query !== null && (trim($query) === '' || ! mb_check_encoding($query, 'UTF-8'))) {
            throw ValidationException::withMessages(['q' => 'Use a nonempty UTF-8 query.']);
        }
        $query = $query === null ? null : trim($query);
        if ($query === null && $parentId !== null && $this->tree->entry($projectId, $parentId)->kind !== 'folder') {
            throw ValidationException::withMessages(['parent_id' => 'The parent must be a folder.']);
        }
        $entries = [];
        foreach (ProjectDocumentEntry::query()->where('project_id', $projectId)->get() as $entry) {
            if (($query === null && $entry->parent_id !== $parentId) || ($kind !== null && $entry->kind !== $kind)) {
                continue;
            }
            $data = DocumentEntryData::fromModel($entry);
            if (($state === 'active' && $data->isArchived) || ($state === 'archived' && ! $data->isArchived)) {
                continue;
            }
            if ($query !== null && mb_stripos($data->path, $query, 0, 'UTF-8') === false) {
                continue;
            }
            $entries[] = $data->toArray();
        }
        usort($entries, self::compare(...));
        $scope = json_encode([$projectId, $parentId, $state, $kind, $query], JSON_THROW_ON_ERROR);

        return $this->page($entries, $scope, $cursor, $limit, false);
    }

    /** @return array{data: list<array<string, mixed>>, next_cursor: ?string} */
    public function versions(int $projectId, int $entryId, ?string $cursor, int $limit): array
    {
        $entry = $this->tree->entry($projectId, $entryId);
        $this->writer->version($entry);
        $versions = [];
        foreach ($entry->versions()->orderByDesc('number')->get() as $version) {
            $versions[] = DocumentVersionData::fromModel($version)->toArray();
        }

        return $this->page($versions, 'versions:'.$projectId.':'.$entryId, $cursor, $limit, true);
    }

    /**
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private static function compare(array $a, array $b): int
    {
        return (($a['kind'] === 'folder' ? 0 : 1) <=> ($b['kind'] === 'folder' ? 0 : 1))
            ?: strcmp(ValidatedData::string($a['name']), ValidatedData::string($b['name'])) ?: ($a['id'] <=> $b['id']);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{data: list<array<string, mixed>>, next_cursor: ?string}
     */
    private function page(array $rows, string $scope, ?string $cursor, int $limit, bool $versions): array
    {
        if ($cursor !== null) {
            try {
                $decoded = json_decode(Crypt::decryptString($cursor), true, flags: JSON_THROW_ON_ERROR);
                if (! is_array($decoded) || ($decoded['scope'] ?? null) !== $scope || ! is_array($decoded['last'] ?? null)) {
                    throw new \RuntimeException;
                }
                $last = $decoded['last'];
                if ($versions) {
                    if (! is_int($last['number'] ?? null)) {
                        throw new \RuntimeException;
                    }
                } elseif (! is_string($last['kind'] ?? null) || ! is_string($last['name'] ?? null) || ! is_int($last['id'] ?? null)) {
                    throw new \RuntimeException;
                }
                $rows = array_values(array_filter($rows, static fn (array $row): bool => $versions ? $row['number'] < $last['number'] : self::compare($row, $last) > 0));
            } catch (Throwable) {
                throw ValidationException::withMessages(['cursor' => 'Cursor is invalid for these filters.']);
            }
        }
        $page = array_slice($rows, 0, $limit);
        $last = $page === [] ? null : $page[count($page) - 1];
        $marker = $last === null ? null : ($versions ? ['number' => $last['number']] : ['kind' => $last['kind'], 'name' => $last['name'], 'id' => $last['id']]);

        return ['data' => $page, 'next_cursor' => count($rows) > $limit ? Crypt::encryptString(json_encode(['scope' => $scope, 'last' => $marker], JSON_THROW_ON_ERROR)) : null];
    }
}
