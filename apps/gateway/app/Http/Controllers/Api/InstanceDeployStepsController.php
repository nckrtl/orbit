<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Instances\CreateInstanceDeployStepAction;
use App\Actions\Instances\DestroyInstanceDeployStepAction;
use App\Actions\Instances\ListInstanceDeployStepsAction;
use App\Actions\Instances\UpdateInstanceDeployStepAction;
use App\Data\Instances\DeploymentStepData;
use App\Domain\Instances\Deployment\DeploymentStep;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Instances\StoreInstanceDeployStepRequest;
use App\Http\Requests\Instances\UpdateInstanceDeployStepRequest;
use App\Models\Instance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class InstanceDeployStepsController extends Controller
{
    #[RequiresNodeAccess(ServingNode::InstanceOwning)]
    public function index(
        Request $request,
        Instance $instance,
        ListInstanceDeployStepsAction $action,
    ): JsonResponse {
        return response()->json([
            'data' => array_map(
                static fn (DeploymentStep $step): array => DeploymentStepData::fromDomain($step)->toArray(),
                $action->execute($instance),
            ),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }

    #[RequiresNodeAccess(ServingNode::InstanceOwning)]
    public function store(
        StoreInstanceDeployStepRequest $request,
        Instance $instance,
        CreateInstanceDeployStepAction $action,
    ): JsonResponse {
        return response()->json([
            'data' => DeploymentStepData::fromDomain(
                $action->execute($instance, $request->step(), $request->beforeStep(), $request->afterStep()),
            )->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ], 201);
    }

    #[RequiresNodeAccess(ServingNode::InstanceOwning)]
    public function update(
        UpdateInstanceDeployStepRequest $request,
        Instance $instance,
        string $step,
        UpdateInstanceDeployStepAction $action,
    ): JsonResponse {
        return response()->json([
            'data' => DeploymentStepData::fromDomain(
                $action->execute(
                    $instance,
                    $step,
                    $request->command(),
                    $request->phase(),
                    $request->timeoutSeconds(),
                    $request->beforeStep(),
                    $request->afterStep(),
                    $request->hasCommand(),
                    $request->hasPhase(),
                    $request->hasTimeout(),
                ),
            )->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }

    #[RequiresNodeAccess(ServingNode::InstanceOwning)]
    public function destroy(
        Request $request,
        Instance $instance,
        string $step,
        DestroyInstanceDeployStepAction $action,
    ): JsonResponse {
        return response()->json([
            'data' => DeploymentStepData::fromDomain($action->execute($instance, $step))->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }
}
