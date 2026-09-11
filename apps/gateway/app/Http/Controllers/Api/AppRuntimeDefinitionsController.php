<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\AppDefinitions\CreateProcessDefinitionAction;
use App\Actions\AppDefinitions\CreateScheduleDefinitionAction;
use App\Actions\AppDefinitions\ListProcessDefinitionsAction;
use App\Actions\AppDefinitions\ListScheduleDefinitionsAction;
use App\Actions\AppDefinitions\RemoveProcessDefinitionAction;
use App\Actions\AppDefinitions\RemoveScheduleDefinitionAction;
use App\Actions\AppDefinitions\ReplaceProcessDefinitionAction;
use App\Actions\AppDefinitions\ReplaceScheduleDefinitionAction;
use App\Actions\AppDefinitions\ShowProcessDefinitionAction;
use App\Actions\AppDefinitions\ShowScheduleDefinitionAction;
use App\Data\AppDefinitions\AppRuntimeDefinitionData;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\AppDefinitions\ProcessDefinitionRequest;
use App\Http\Requests\AppDefinitions\ScheduleDefinitionRequest;
use App\Models\App as OrbitApp;
use App\Models\ProcessDefinition;
use App\Models\ScheduleDefinition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AppRuntimeDefinitionsController extends Controller
{
    #[RequiresNodeAccess(ServingNode::AppOwning)]
    public function processIndex(
        Request $request,
        OrbitApp $app,
        ListProcessDefinitionsAction $action,
    ): JsonResponse {
        return response()->json([
            'data' => $action
                ->handle($app)
                ->map(static fn (ProcessDefinition $definition): array => AppRuntimeDefinitionData::fromModel(
                    $definition,
                    includeCommand: false,
                )->toArray())
                ->values()
                ->all(),
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::AppOwning)]
    public function processStore(
        ProcessDefinitionRequest $request,
        OrbitApp $app,
        CreateProcessDefinitionAction $action,
    ): JsonResponse {
        return response()->json([
            'data' => AppRuntimeDefinitionData::fromModel($action->execute($app, $request->payload()))->toArray(),
            'meta' => $this->meta($request),
        ], 201);
    }

    #[RequiresNodeAccess(ServingNode::AppOwning)]
    public function processShow(
        Request $request,
        OrbitApp $app,
        ProcessDefinition $processDefinition,
        ShowProcessDefinitionAction $action,
    ): JsonResponse {
        return response()->json([
            'data' => AppRuntimeDefinitionData::fromModel($action->handle($processDefinition))->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::AppOwning)]
    public function processUpdate(
        ProcessDefinitionRequest $request,
        OrbitApp $app,
        ProcessDefinition $processDefinition,
        ReplaceProcessDefinitionAction $action,
    ): JsonResponse {
        return response()->json([
            'data' => AppRuntimeDefinitionData::fromModel(
                $action->execute($processDefinition, $request->payload()),
            )->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::AppOwning)]
    public function processDestroy(
        Request $request,
        OrbitApp $app,
        ProcessDefinition $processDefinition,
        RemoveProcessDefinitionAction $action,
    ): JsonResponse {
        $data = AppRuntimeDefinitionData::fromModel($processDefinition)->toArray();
        $action->execute($processDefinition);

        return response()->json([
            'data' => $data,
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::AppOwning)]
    public function scheduleIndex(
        Request $request,
        OrbitApp $app,
        ListScheduleDefinitionsAction $action,
    ): JsonResponse {
        return response()->json([
            'data' => $action
                ->handle($app)
                ->map(static fn (ScheduleDefinition $definition): array => AppRuntimeDefinitionData::fromModel(
                    $definition,
                    includeCommand: false,
                )->toArray())
                ->values()
                ->all(),
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::AppOwning)]
    public function scheduleStore(
        ScheduleDefinitionRequest $request,
        OrbitApp $app,
        CreateScheduleDefinitionAction $action,
    ): JsonResponse {
        return response()->json([
            'data' => AppRuntimeDefinitionData::fromModel($action->execute($app, $request->payload()))->toArray(),
            'meta' => $this->meta($request),
        ], 201);
    }

    #[RequiresNodeAccess(ServingNode::AppOwning)]
    public function scheduleShow(
        Request $request,
        OrbitApp $app,
        ScheduleDefinition $scheduleDefinition,
        ShowScheduleDefinitionAction $action,
    ): JsonResponse {
        return response()->json([
            'data' => AppRuntimeDefinitionData::fromModel($action->handle($scheduleDefinition))->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::AppOwning)]
    public function scheduleUpdate(
        ScheduleDefinitionRequest $request,
        OrbitApp $app,
        ScheduleDefinition $scheduleDefinition,
        ReplaceScheduleDefinitionAction $action,
    ): JsonResponse {
        return response()->json([
            'data' => AppRuntimeDefinitionData::fromModel(
                $action->execute($scheduleDefinition, $request->payload()),
            )->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::AppOwning)]
    public function scheduleDestroy(
        Request $request,
        OrbitApp $app,
        ScheduleDefinition $scheduleDefinition,
        RemoveScheduleDefinitionAction $action,
    ): JsonResponse {
        $data = AppRuntimeDefinitionData::fromModel($scheduleDefinition)->toArray();
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
