<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\ProjectDefinitions\CreateProcessDefinitionAction;
use App\Actions\ProjectDefinitions\CreateScheduleDefinitionAction;
use App\Actions\ProjectDefinitions\ListProcessDefinitionsAction;
use App\Actions\ProjectDefinitions\ListScheduleDefinitionsAction;
use App\Actions\ProjectDefinitions\RemoveProcessDefinitionAction;
use App\Actions\ProjectDefinitions\RemoveScheduleDefinitionAction;
use App\Actions\ProjectDefinitions\ReplaceProcessDefinitionAction;
use App\Actions\ProjectDefinitions\ReplaceScheduleDefinitionAction;
use App\Actions\ProjectDefinitions\ShowProcessDefinitionAction;
use App\Actions\ProjectDefinitions\ShowScheduleDefinitionAction;
use App\Data\ProjectDefinitions\ProjectRuntimeDefinitionData;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\ProjectDefinitions\ProcessDefinitionRequest;
use App\Http\Requests\ProjectDefinitions\ScheduleDefinitionRequest;
use App\Models\ProcessDefinition;
use App\Models\Project;
use App\Models\ScheduleDefinition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ProjectRuntimeDefinitionsController extends Controller
{
    #[RequiresNodeAccess(ServingNode::ProjectOwning)]
    public function processIndex(
        Request $request,
        Project $project,
        ListProcessDefinitionsAction $action,
    ): JsonResponse {
        return response()->json([
            'data' => $action
                ->handle($project)
                ->map(static fn (ProcessDefinition $definition): array => ProjectRuntimeDefinitionData::fromModel(
                    $definition,
                    includeCommand: false,
                )->toArray())
                ->values()
                ->all(),
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::ProjectOwning)]
    public function processStore(
        ProcessDefinitionRequest $request,
        Project $project,
        CreateProcessDefinitionAction $action,
    ): JsonResponse {
        return response()->json([
            'data' => ProjectRuntimeDefinitionData::fromModel($action->execute($project, $request->payload()))->toArray(),
            'meta' => $this->meta($request),
        ], 201);
    }

    #[RequiresNodeAccess(ServingNode::ProjectOwning)]
    public function processShow(
        Request $request,
        Project $project,
        ProcessDefinition $processDefinition,
        ShowProcessDefinitionAction $action,
    ): JsonResponse {
        return response()->json([
            'data' => ProjectRuntimeDefinitionData::fromModel($action->handle($processDefinition))->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::ProjectOwning)]
    public function processUpdate(
        ProcessDefinitionRequest $request,
        Project $project,
        ProcessDefinition $processDefinition,
        ReplaceProcessDefinitionAction $action,
    ): JsonResponse {
        return response()->json([
            'data' => ProjectRuntimeDefinitionData::fromModel(
                $action->execute($processDefinition, $request->payload()),
            )->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::ProjectOwning)]
    public function processDestroy(
        Request $request,
        Project $project,
        ProcessDefinition $processDefinition,
        RemoveProcessDefinitionAction $action,
    ): JsonResponse {
        $data = ProjectRuntimeDefinitionData::fromModel($processDefinition)->toArray();
        $action->execute($processDefinition);

        return response()->json([
            'data' => $data,
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::ProjectOwning)]
    public function scheduleIndex(
        Request $request,
        Project $project,
        ListScheduleDefinitionsAction $action,
    ): JsonResponse {
        return response()->json([
            'data' => $action
                ->handle($project)
                ->map(static fn (ScheduleDefinition $definition): array => ProjectRuntimeDefinitionData::fromModel(
                    $definition,
                    includeCommand: false,
                )->toArray())
                ->values()
                ->all(),
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::ProjectOwning)]
    public function scheduleStore(
        ScheduleDefinitionRequest $request,
        Project $project,
        CreateScheduleDefinitionAction $action,
    ): JsonResponse {
        return response()->json([
            'data' => ProjectRuntimeDefinitionData::fromModel($action->execute($project, $request->payload()))->toArray(),
            'meta' => $this->meta($request),
        ], 201);
    }

    #[RequiresNodeAccess(ServingNode::ProjectOwning)]
    public function scheduleShow(
        Request $request,
        Project $project,
        ScheduleDefinition $scheduleDefinition,
        ShowScheduleDefinitionAction $action,
    ): JsonResponse {
        return response()->json([
            'data' => ProjectRuntimeDefinitionData::fromModel($action->handle($scheduleDefinition))->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::ProjectOwning)]
    public function scheduleUpdate(
        ScheduleDefinitionRequest $request,
        Project $project,
        ScheduleDefinition $scheduleDefinition,
        ReplaceScheduleDefinitionAction $action,
    ): JsonResponse {
        return response()->json([
            'data' => ProjectRuntimeDefinitionData::fromModel(
                $action->execute($scheduleDefinition, $request->payload()),
            )->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::ProjectOwning)]
    public function scheduleDestroy(
        Request $request,
        Project $project,
        ScheduleDefinition $scheduleDefinition,
        RemoveScheduleDefinitionAction $action,
    ): JsonResponse {
        $data = ProjectRuntimeDefinitionData::fromModel($scheduleDefinition)->toArray();
        $action->execute($scheduleDefinition);

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
