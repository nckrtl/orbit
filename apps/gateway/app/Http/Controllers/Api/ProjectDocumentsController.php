<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\ProjectDocuments\BrowseDocumentsAction;
use App\Actions\ProjectDocuments\DocumentTreeAction;
use App\Actions\ProjectDocuments\WriteDocumentAction;
use App\Data\ProjectDocuments\DocumentBody;
use App\Data\ProjectDocuments\DocumentEntryData;
use App\Data\ProjectDocuments\DocumentVersionData;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\ProjectDocuments\CreateDocumentRequest;
use App\Http\Requests\ProjectDocuments\DestroyDocumentRequest;
use App\Http\Requests\ProjectDocuments\DocumentContentRequest;
use App\Http\Requests\ProjectDocuments\DocumentRevisionRequest;
use App\Http\Requests\ProjectDocuments\DocumentVersionsRequest;
use App\Http\Requests\ProjectDocuments\ListDocumentsRequest;
use App\Http\Requests\ProjectDocuments\RestoreDocumentVersionRequest;
use App\Http\Requests\ProjectDocuments\SearchDocumentsRequest;
use App\Http\Requests\ProjectDocuments\UpdateDocumentRequest;
use App\Http\Requests\ProjectDocuments\WriteDocumentRequest;
use App\Infrastructure\ProjectDocuments\DocumentBodies;
use App\Models\Node;
use App\Models\Project;
use App\Models\ProjectDocumentEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

#[RequiresNodeAccess(ServingNode::ProjectOwning)]
final class ProjectDocumentsController extends Controller
{
    public function index(ListDocumentsRequest $request, Project $project, BrowseDocumentsAction $browse): JsonResponse
    {
        $page = $browse->entries($project->id, $request->integerValue('parent_id'), $request->textValue('state', 'active') ?? 'active', $request->textValue('kind'), null, $request->textValue('cursor'), $request->integerValue('limit', 50) ?? 50);

        return $this->response($request, $page['data'], cursor: $page['next_cursor'], paginated: true);
    }

    public function search(SearchDocumentsRequest $request, Project $project, BrowseDocumentsAction $browse): JsonResponse
    {
        $page = $browse->entries($project->id, null, $request->textValue('state', 'active') ?? 'active', $request->textValue('kind'), $request->textValue('q'), $request->textValue('cursor'), $request->integerValue('limit', 50) ?? 50);

        return $this->response($request, $page['data'], cursor: $page['next_cursor'], paginated: true);
    }

    public function store(CreateDocumentRequest $request, Project $project, DocumentTreeAction $tree, WriteDocumentAction $writer): JsonResponse
    {
        $name = $request->textValue('name') ?? '';
        $parent = $request->integerValue('parent_id');
        if ($request->textValue('kind') === 'folder') {
            if (array_intersect(array_keys($request->validated()), ['content_text', 'content_base64', 'media_type']) !== []) {
                throw ValidationException::withMessages(['kind' => 'Folders cannot contain a body or media type.']);
            }
            $entry = $tree->folder($project->id, $name, $parent);
        } else {
            $entry = $writer->create($project->id, $name, $parent, $request->bodyData(), $this->author($request));
        }

        return $this->entryResponse($request, $entry, 201);
    }

    public function show(Request $request, Project $project, int $entry, DocumentTreeAction $tree): JsonResponse
    {
        return $this->entryResponse($request, $tree->entry($project->id, $entry));
    }

    public function update(UpdateDocumentRequest $request, Project $project, int $entry, DocumentTreeAction $tree): JsonResponse
    {
        $changes = [];
        if (array_key_exists('name', $request->validated())) {
            $changes['name'] = $request->textValue('name') ?? '';
        }
        if (array_key_exists('parent_id', $request->validated())) {
            $changes['parent_id'] = $request->integerValue('parent_id');
        }

        return $this->entryResponse($request, $tree->update($project->id, $entry, $request->revision(), $changes));
    }

    public function write(WriteDocumentRequest $request, Project $project, int $entry, DocumentTreeAction $tree, WriteDocumentAction $writer): JsonResponse
    {
        $version = $writer->version($tree->entry($project->id, $entry));

        return $this->entryResponse($request, $writer->write($project->id, $entry, $request->revision(), $request->bodyData($version->media_type), $this->author($request)));
    }

    public function read(DocumentContentRequest $request, Project $project, int $entry, DocumentTreeAction $tree, WriteDocumentAction $writer, DocumentBodies $bodies): JsonResponse
    {
        $file = $tree->entry($project->id, $entry);
        $version = $writer->version($file, $request->integerValue('version'));
        $body = new DocumentBody($bodies->get($version->storage_key, $version->size_bytes, $version->sha256), $version->media_type);
        $body->assertEditable();

        return $this->response($request, ['entry_id' => $file->id, 'revision' => $file->revision, 'version' => DocumentVersionData::fromModel($version)->toArray(), 'content_text' => $body->bytes]);
    }

    public function download(DocumentContentRequest $request, Project $project, int $entry, DocumentTreeAction $tree, WriteDocumentAction $writer, DocumentBodies $bodies): JsonResponse
    {
        $file = $tree->entry($project->id, $entry);
        $version = $writer->version($file, $request->integerValue('version'));

        return $this->response($request, ['entry_id' => $file->id, 'revision' => $file->revision, 'version' => DocumentVersionData::fromModel($version)->toArray(), 'content_base64' => base64_encode($bodies->get($version->storage_key, $version->size_bytes, $version->sha256))]);
    }

    public function versions(DocumentVersionsRequest $request, Project $project, int $entry, BrowseDocumentsAction $browse): JsonResponse
    {
        $page = $browse->versions($project->id, $entry, $request->textValue('cursor'), $request->integerValue('limit', 50) ?? 50);

        return $this->response($request, $page['data'], cursor: $page['next_cursor'], paginated: true);
    }

    public function restoreVersion(RestoreDocumentVersionRequest $request, Project $project, int $entry, WriteDocumentAction $writer): JsonResponse
    {
        return $this->entryResponse($request, $writer->restoreVersion($project->id, $entry, $request->revision(), $request->integerValue('version_id') ?? 0, $this->author($request)));
    }

    public function archive(DocumentRevisionRequest $request, Project $project, int $entry, DocumentTreeAction $tree): JsonResponse
    {
        return $this->entryResponse($request, $tree->archive($project->id, $entry, $request->revision(), true));
    }

    public function restore(DocumentRevisionRequest $request, Project $project, int $entry, DocumentTreeAction $tree): JsonResponse
    {
        return $this->entryResponse($request, $tree->archive($project->id, $entry, $request->revision(), false));
    }

    public function destroy(DestroyDocumentRequest $request, Project $project, int $entry, DocumentTreeAction $tree): JsonResponse
    {
        $pending = $tree->remove($project->id, $entry, $request->revision(), (bool) $request->validated('recursive', false));

        return $this->response($request, ['id' => $entry, 'removed' => true, 'cleanup_pending' => $pending]);
    }

    private function author(Request $request): ?int
    {
        $node = ($request->getUserResolver())();

        return $node instanceof Node ? $node->id : null;
    }

    private function entryResponse(Request $request, ProjectDocumentEntry $entry, int $status = 200): JsonResponse
    {
        return $this->response($request, DocumentEntryData::fromModel($entry)->toArray(), $status);
    }

    /** @param array<array-key, mixed> $data */
    private function response(Request $request, array $data, int $status = 200, ?string $cursor = null, bool $paginated = false): JsonResponse
    {
        return response()->json(['data' => $data, 'meta' => ['request_id' => $request->attributes->getString('orbit.request_id'), ...($paginated ? ['next_cursor' => $cursor] : [])]], $status)->header('Cache-Control', 'no-store');
    }
}
