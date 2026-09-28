<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Tasks\ShowTasksStatusAction;
use App\Data\Tasks\TaskExtensionStatusData;
use App\Data\Tasks\TasksStatusData;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[RequiresNodeAccess(ServingNode::Gateway)]
final class TasksController extends Controller
{
    public function status(Request $request, ShowTasksStatusAction $action): JsonResponse
    {
        return $this->statusResponse($request, $action->execute());
    }

    private function statusResponse(Request $request, TaskExtensionStatusData|TasksStatusData $status): JsonResponse
    {
        return response()->json([
            'data' => $status->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }
}
