<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Instances\Dependencies\AccessInstanceDependenciesAction;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Instances\InstanceDependenciesRequest;
use App\Models\Instance;
use App\Models\Node;
use Illuminate\Http\JsonResponse;

#[RequiresNodeAccess(ServingNode::InstanceOwning)]
final class InstanceDependenciesController extends Controller
{
    public function show(InstanceDependenciesRequest $request, Instance $instance, AccessInstanceDependenciesAction $action): JsonResponse
    {
        return $this->respond($request, $instance, $action, false);
    }

    public function scan(InstanceDependenciesRequest $request, Instance $instance, AccessInstanceDependenciesAction $action): JsonResponse
    {
        return $this->respond($request, $instance, $action, true);
    }

    public function update(InstanceDependenciesRequest $request, Instance $instance, AccessInstanceDependenciesAction $action): JsonResponse
    {
        $consumer = $request->user();
        abort_unless($consumer instanceof Node, 401);
        $data = $action->update($instance, $consumer);

        return response()->json([
            'data' => $data->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }

    private function respond(InstanceDependenciesRequest $request, Instance $instance, AccessInstanceDependenciesAction $action, bool $scan): JsonResponse
    {
        $consumer = $request->user();
        abort_unless($consumer instanceof Node, 401);
        $data = $action->execute($instance, $consumer, $scan);

        return response()->json([
            'data' => $data->toArray(),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }
}
