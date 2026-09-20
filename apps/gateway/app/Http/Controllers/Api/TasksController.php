<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Tasks\DisableTasksAction;
use App\Actions\Tasks\EnableTasksAction;
use App\Actions\Tasks\ShowTasksStatusAction;
use App\Data\Tasks\TaskExtensionStatusData;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tasks\EmptyTasksRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[RequiresNodeAccess(ServingNode::Gateway)]
final class TasksController extends Controller
{
    public function enable(EmptyTasksRequest $request, EnableTasksAction $action): JsonResponse
    {
        return $this->statusResponse($request, $action->execute());
    }

    public function disable(EmptyTasksRequest $request, DisableTasksAction $action): JsonResponse
    {
        return $this->statusResponse($request, $action->execute());
    }

    public function status(Request $request, ShowTasksStatusAction $action): JsonResponse
    {
        return $this->statusResponse($request, $action->execute());
    }

    private function statusResponse(Request $request, TaskExtensionStatusData $status): JsonResponse
    {
        return response()->json([
            'data' => $status->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }
}
