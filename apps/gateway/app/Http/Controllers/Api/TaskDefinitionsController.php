<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Tasks\CreateTaskDefinitionAction;
use App\Actions\Tasks\DestroyTaskDefinitionAction;
use App\Actions\Tasks\ListTaskDefinitionsAction;
use App\Actions\Tasks\ReplaceTaskDefinitionAction;
use App\Actions\Tasks\ShowTaskDefinitionAction;
use App\Data\Tasks\TaskDefinitionData;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tasks\EmptyTasksRequest;
use App\Http\Requests\Tasks\ListTaskDefinitionsRequest;
use App\Http\Requests\Tasks\TaskDefinitionRequest;
use App\Models\Project;
use App\Models\TaskDefinition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TaskDefinitionsController extends Controller
{
    #[RequiresNodeAccess(ServingNode::Collection)]
    public function index(ListTaskDefinitionsRequest $request, ListTaskDefinitionsAction $action): JsonResponse
    {
        return response()->json([
            'data' => $action->execute($request->projectId())
                ->map(static fn (TaskDefinition $definition): array => TaskDefinitionData::fromModel($definition)->toArray())
                ->values()
                ->all(),
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::Collection)]
    public function show(Request $request, Project $project, string $name, ShowTaskDefinitionAction $action): JsonResponse
    {
        return response()->json([
            'data' => TaskDefinitionData::fromModel($action->execute($project, $name))->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::Gateway)]
    public function store(TaskDefinitionRequest $request, Project $project, CreateTaskDefinitionAction $action): JsonResponse
    {
        return response()->json([
            'data' => TaskDefinitionData::fromModel($action->execute($project, $request->definition()))->toArray(),
            'meta' => $this->meta($request),
        ], 201);
    }

    #[RequiresNodeAccess(ServingNode::Gateway)]
    public function update(
        TaskDefinitionRequest $request,
        Project $project,
        string $name,
        ShowTaskDefinitionAction $show,
        ReplaceTaskDefinitionAction $replace,
    ): JsonResponse {
        return response()->json([
            'data' => TaskDefinitionData::fromModel($replace->execute($show->execute($project, $name), $request->definition()))->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::Gateway)]
    public function destroy(
        EmptyTasksRequest $request,
        Project $project,
        string $name,
        ShowTaskDefinitionAction $show,
        DestroyTaskDefinitionAction $destroy,
    ): JsonResponse {
        $definition = $show->execute($project, $name);
        $data = TaskDefinitionData::fromModel($definition)->toArray();
        $destroy->execute($definition);

        return response()->json([
            'data' => $data,
            'meta' => $this->meta($request),
        ]);
    }

    /** @return array{request_id: string} */
    private function meta(Request $request): array
    {
        return ['request_id' => $request->attributes->getString('orbit.request_id')];
    }
}
