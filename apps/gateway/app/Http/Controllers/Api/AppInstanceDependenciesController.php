<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\AppInstances\Dependencies\AccessInstanceDependenciesAction;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\AppInstances\InstanceDependenciesRequest;
use App\Models\AppInstance;
use App\Models\Node;
use Illuminate\Http\JsonResponse;

#[RequiresNodeAccess(ServingNode::InstanceOwning)]
final class AppInstanceDependenciesController extends Controller
{
    public function show(InstanceDependenciesRequest $request, AppInstance $instance, AccessInstanceDependenciesAction $action): JsonResponse
    {
        return $this->respond($request, $instance, $action, false);
    }

    public function scan(InstanceDependenciesRequest $request, AppInstance $instance, AccessInstanceDependenciesAction $action): JsonResponse
    {
        return $this->respond($request, $instance, $action, true);
    }

    private function respond(InstanceDependenciesRequest $request, AppInstance $instance, AccessInstanceDependenciesAction $action, bool $scan): JsonResponse
    {
        /** @var Node $consumer */
        $consumer = $request->user();
        $data = $action->execute($instance, $consumer, $scan);

        return response()->json([
            'data' => $data->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }
}
