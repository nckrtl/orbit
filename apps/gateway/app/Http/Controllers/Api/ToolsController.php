<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Tools\InstallToolAction;
use App\Actions\Tools\ListToolsAction;
use App\Actions\Tools\RemoveToolAction;
use App\Actions\Tools\UpdateToolAction;
use App\Data\Tools\ToolData;
use App\Domain\Tools\ToolOperation;
use App\Domain\Tools\ToolOutcome;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tools\ListToolsRequest;
use App\Http\Requests\Tools\StoreToolRequest;
use App\Models\Tool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[RequiresNodeAccess(ServingNode::ToolOwning)]
final class ToolsController extends Controller
{
    public function index(ListToolsRequest $request, ListToolsAction $action): JsonResponse
    {
        return response()->json([
            'data' => $action
                ->execute($request->nodeId())
                ->map(static fn (Tool $tool): array => ToolData::fromModel($tool)->toArray())
                ->all(),
            'meta' => $this->meta($request),
        ]);
    }

    public function show(Request $request, Tool $tool): JsonResponse
    {
        return response()->json([
            'data' => ToolData::fromModel($tool)->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    public function store(StoreToolRequest $request, InstallToolAction $action): JsonResponse
    {
        $result = $action->execute($request->payload());
        $tool = $result->tool;
        $tool->loadMissing('manager');
        $this->setToolActivity($tool, ToolOperation::Install, $result->outcome);

        return response()->json(
            [
                'data' => ToolData::fromModel($tool, $result->outcome)->toArray(),
                'meta' => $this->meta($request),
            ],
            $result->created ? 201 : 200,
        );
    }

    public function update(
        Request $request,
        Tool $tool,
        UpdateToolAction $action,
    ): JsonResponse {
        $tool->loadMissing('manager');
        $request->attributes->set('orbit.tool_snapshot', $tool);
        $result = $action->execute($tool);
        $this->setToolActivity($result->tool, ToolOperation::Update, $result->outcome);

        return response()->json([
            'data' => ToolData::fromModel($result->tool, $result->outcome)->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    public function destroy(
        Request $request,
        Tool $tool,
        RemoveToolAction $action,
    ): JsonResponse {
        $tool->loadMissing('manager');
        $request->attributes->set('orbit.tool_snapshot', $tool);
        $result = $action->execute($tool);
        $this->setToolActivity($result->tool, ToolOperation::Remove, $result->outcome);

        return response()->json([
            'data' => ToolData::fromModel($result->tool, $result->outcome)->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    private function setToolActivity(
        Tool $tool,
        ToolOperation $operation,
        ToolOutcome $outcome,
    ): void {
        $tool->loadMissing('manager');
        $activityRequest = request();
        $activityRequest->attributes->set('orbit.tool_snapshot', $tool);
        $activityRequest->attributes->set('orbit.tool_activity', [
            'node_id' => $tool->node_id,
            'manager' => $tool->manager->name->value,
            'package' => $tool->package,
            'operation' => $operation->value,
            'outcome' => $outcome->value,
            'version_constraint' => $tool->version_constraint,
        ]);
    }

    /** @return array{request_id: string} */
    private function meta(Request $request): array
    {
        return ['request_id' => $request->attributes->getString('orbit.request_id')];
    }
}
