<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Tasks\CancelTaskCheckAction;
use App\Actions\Tasks\CancelTaskGroupAction;
use App\Actions\Tasks\CompleteTaskGroupAction;
use App\Actions\Tasks\CreateTaskAction;
use App\Actions\Tasks\CreateTaskGroupAction;
use App\Actions\Tasks\DestroyTaskAction;
use App\Actions\Tasks\ListTaskGroupsAction;
use App\Actions\Tasks\ShowTaskGroupAction;
use App\Actions\Tasks\StoreTaskCommentAction;
use App\Actions\Tasks\UpdateTaskAction;
use App\Actions\Tasks\UpdateTaskGroupAction;
use App\Data\Tasks\TaskCheckData;
use App\Data\Tasks\TaskCommentData;
use App\Data\Tasks\TaskData;
use App\Data\Tasks\TaskGroupData;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tasks\CreateTaskGroupRequest;
use App\Http\Requests\Tasks\CreateTaskRequest;
use App\Http\Requests\Tasks\EmptyTasksRequest;
use App\Http\Requests\Tasks\ListTaskGroupsRequest;
use App\Http\Requests\Tasks\StoreTaskCommentRequest;
use App\Http\Requests\Tasks\UpdateTaskGroupRequest;
use App\Http\Requests\Tasks\UpdateTaskRequest;
use App\Models\Task;
use App\Models\TaskComment;
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

    #[RequiresNodeAccess(ServingNode::TaskGroupOwning)]
    public function update(UpdateTaskGroupRequest $request, TaskGroup $group, UpdateTaskGroupAction $action): JsonResponse
    {
        return response()->json([
            'data' => TaskGroupData::fromModel($action->execute($group, $request->payload()))->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::TaskGroupOwning)]
    public function createTask(CreateTaskRequest $request, TaskGroup $group, CreateTaskAction $action): JsonResponse
    {
        $task = $action->execute($group, $request->payload());

        return response()->json([
            'data' => TaskData::fromModel($task)->toArray(),
            'meta' => $this->meta($request),
        ], 201);
    }

    #[RequiresNodeAccess(ServingNode::TaskGroupOwning)]
    public function updateTask(UpdateTaskRequest $request, TaskGroup $group, Task $task, UpdateTaskAction $action): JsonResponse
    {
        abort_unless($task->task_group_id === $group->id, 404);

        return response()->json([
            'data' => TaskData::fromModel($action->execute($group, $task, $request->payload()))->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::TaskGroupOwning)]
    public function destroyTask(EmptyTasksRequest $request, TaskGroup $group, Task $task, DestroyTaskAction $action): JsonResponse
    {
        abort_unless($task->task_group_id === $group->id, 404);

        return response()->json([
            'data' => TaskData::fromModel($action->execute($group, $task))->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::Gateway)]
    public function cancelCheck(EmptyTasksRequest $request, TaskGroup $group, Task $task, CancelTaskCheckAction $action): JsonResponse
    {
        abort_unless($task->task_group_id === $group->id, 404);

        return response()->json([
            'data' => TaskCheckData::fromModel($action->execute($group, $task))->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::Gateway)]
    public function storeComment(StoreTaskCommentRequest $request, TaskGroup $group, Task $task, StoreTaskCommentAction $action): JsonResponse
    {
        abort_unless($task->task_group_id === $group->id, 404);
        $comment = $action->execute($task, $request->validated());

        return response()->json(['data' => TaskCommentData::fromModel($comment)->toArray(), 'meta' => $this->meta($request)], 201);
    }

    #[RequiresNodeAccess(ServingNode::Gateway)]
    public function comments(Request $request, TaskGroup $group, Task $task): JsonResponse
    {
        abort_unless($task->task_group_id === $group->id, 404);

        return response()->json([
            'data' => $task->comments()->latest('posted_at')->get()->map(static fn (TaskComment $comment): array => TaskCommentData::fromModel($comment)->toArray())->all(),
            'meta' => $this->meta($request),
        ]);
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

    #[RequiresNodeAccess(ServingNode::Gateway)]
    public function complete(EmptyTasksRequest $request, TaskGroup $group, CompleteTaskGroupAction $action): JsonResponse
    {
        return response()->json([
            'data' => TaskGroupData::fromModel($action->execute($group))->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::Gateway)]
    public function cancel(EmptyTasksRequest $request, TaskGroup $group, CancelTaskGroupAction $action): JsonResponse
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
