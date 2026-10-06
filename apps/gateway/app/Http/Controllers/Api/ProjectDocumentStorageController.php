<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\ProjectDocuments\UpdateDocumentStorageAction;
use App\Data\ProjectDocuments\DocumentStorageData;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\ProjectDocuments\UpdateDocumentStorageRequest;
use App\Models\ProjectDocumentStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[RequiresNodeAccess(ServingNode::Gateway)]
final class ProjectDocumentStorageController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return $this->response($request, DocumentStorageData::fromModel(ProjectDocumentStorage::query()->findOrFail(1)));
    }

    public function update(UpdateDocumentStorageRequest $request, UpdateDocumentStorageAction $action): JsonResponse
    {
        return $this->response($request, DocumentStorageData::fromModel($action->handle($request->toData())));
    }

    private function response(Request $request, DocumentStorageData $data): JsonResponse
    {
        return response()->json([
            'data' => $data->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ])->header('Cache-Control', 'no-store');
    }
}
