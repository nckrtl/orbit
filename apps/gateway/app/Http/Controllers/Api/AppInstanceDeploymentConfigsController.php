<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\AppInstances\ShowAppInstanceDeploymentConfigAction;
use App\Actions\AppInstances\UpdateAppInstanceDeploymentConfigAction;
use App\Data\AppInstances\DeploymentConfigData;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\AppInstances\UpdateAppInstanceDeploymentConfigRequest;
use App\Models\AppInstance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AppInstanceDeploymentConfigsController extends Controller
{
    #[RequiresNodeAccess(ServingNode::InstanceOwning)]
    public function show(
        Request $request,
        AppInstance $instance,
        ShowAppInstanceDeploymentConfigAction $action,
    ): JsonResponse {
        return response()->json([
            'data' => DeploymentConfigData::fromDomain($action->execute($instance))->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }

    #[RequiresNodeAccess(ServingNode::InstanceOwning)]
    public function update(
        UpdateAppInstanceDeploymentConfigRequest $request,
        AppInstance $instance,
        UpdateAppInstanceDeploymentConfigAction $action,
    ): JsonResponse {
        return response()->json([
            'data' => DeploymentConfigData::fromDomain(
                $action->execute($instance, $request->deploymentConfig()),
            )->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }
}
