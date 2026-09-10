<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\AppInstances\AdoptProductionLayoutAction;
use App\Data\AppInstances\AppInstanceData;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\AppInstances\PrepareAppInstanceDeploymentLayoutRequest;
use App\Models\AppInstance;
use Illuminate\Http\JsonResponse;

final class AppInstanceDeploymentLayoutsController extends Controller
{
    #[RequiresNodeAccess(ServingNode::InstanceOwning)]
    public function store(
        PrepareAppInstanceDeploymentLayoutRequest $request,
        AppInstance $instance,
        AdoptProductionLayoutAction $action,
    ): JsonResponse {
        return response()->json([
            'data' => AppInstanceData::fromModel($action->execute($instance, $request->payload()))->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }
}
