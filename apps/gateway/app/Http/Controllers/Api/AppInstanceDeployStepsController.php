<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\AppInstances\CreateAppInstanceDeployStepAction;
use App\Actions\AppInstances\DestroyAppInstanceDeployStepAction;
use App\Actions\AppInstances\ListAppInstanceDeployStepsAction;
use App\Actions\AppInstances\UpdateAppInstanceDeployStepAction;
use App\Data\AppInstances\DeploymentStepData;
use App\Domain\AppInstances\Deployment\DeploymentStep;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\AppInstances\StoreAppInstanceDeployStepRequest;
use App\Http\Requests\AppInstances\UpdateAppInstanceDeployStepRequest;
use App\Models\AppInstance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AppInstanceDeployStepsController extends Controller
{
    #[RequiresNodeAccess(ServingNode::InstanceOwning)]
    public function index(
        Request $request,
        AppInstance $instance,
        ListAppInstanceDeployStepsAction $action,
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
        StoreAppInstanceDeployStepRequest $request,
        AppInstance $instance,
        CreateAppInstanceDeployStepAction $action,
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
        UpdateAppInstanceDeployStepRequest $request,
        AppInstance $instance,
        string $step,
        UpdateAppInstanceDeployStepAction $action,
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
        AppInstance $instance,
        string $step,
        DestroyAppInstanceDeployStepAction $action,
    ): JsonResponse {
        return response()->json([
            'data' => DeploymentStepData::fromDomain($action->execute($instance, $step))->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }
}
