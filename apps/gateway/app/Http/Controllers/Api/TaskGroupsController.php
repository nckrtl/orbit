<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Tasks\AddTaskAction;
use App\Actions\Tasks\CreateTaskGroupAction;
use App\Actions\Tasks\ListTaskGroupsAction;
use App\Actions\Tasks\ShowTaskGroupAction;
use App\Data\Tasks\TaskData;
use App\Data\Tasks\TaskGroupData;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tasks\AddTaskRequest;
use App\Http\Requests\Tasks\CreateTaskGroupRequest;
use App\Http\Requests\Tasks\ListTaskGroupsRequest;
use App\Models\TaskGroup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TaskGroupsController extends Controller
{
    #[RequiresNodeAccess(ServingNode::Gateway)]
    public function store(CreateTaskGroupRequest $request, CreateTaskGroupAction $action): JsonResponse
    {
        $group = $action->execute($request->payload());

        return response()->json([
            'data' => TaskGroupData::fromModel($group)->toArray(),
            'meta' => $this->meta($request),
        ], 201);
    }

    #[RequiresNodeAccess(ServingNode::Gateway)]
    public function addTask(AddTaskRequest $request, TaskGroup $group, AddTaskAction $action): JsonResponse
    {
        $task = $action->execute($group, $request->payload());

        return response()->json([
            'data' => TaskData::fromModel($task)->toArray(),
            'meta' => $this->meta($request),
        ], 201);
    }

    #[RequiresNodeAccess(ServingNode::Collection)]
    public function index(ListTaskGroupsRequest $request, ListTaskGroupsAction $action): JsonResponse
    {
        return response()->json([
            'data' => $action->execute($request->appId(), $request->status())
                ->map(static fn (TaskGroup $group): array => TaskGroupData::fromModel($group)->toArray())
                ->values()
                ->all(),
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::Collection)]
    public function show(Request $request, TaskGroup $group, ShowTaskGroupAction $action): JsonResponse
    {
        return response()->json([
            'data' => TaskGroupData::fromModel($action->execute($group))->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    /** @return array{request_id: string} */
    private function meta(Request $request): array
    {
        return ['request_id' => $request->attributes->getString('orbit.request_id')];
    }
}
