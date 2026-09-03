<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\AppInstances\CreateAppInstanceAction;
use App\Actions\AppInstances\ListAppInstancesAction;
use App\Actions\AppInstances\RemoveAppInstanceAction;
use App\Actions\AppInstances\ShowAppInstanceAction;
use App\Data\AppInstances\AppInstanceData;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\AppInstances\RemoveAppInstanceRequest;
use App\Http\Requests\AppInstances\StoreAppInstanceRequest;
use App\Models\AppInstance;
use App\Models\Node;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AppInstancesController extends Controller
{
    #[RequiresNodeAccess(ServingNode::Collection)]
    public function index(Request $request, ListAppInstancesAction $action): JsonResponse
    {
        /** @mago-expect analysis:mixed-assignment The authenticated peer resolver returns a Node. */
        $consumer = $request->user();
        assert($consumer instanceof Node, description: 'Authenticated peer must be a Node.');

        return response()->json([
            'data' => $action
                ->handle($consumer)
                ->map(static fn (AppInstance $row): array => AppInstanceData::fromModel($row)->toArray())
                ->values()
                ->all(),
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::InstanceOwning)]
    public function store(StoreAppInstanceRequest $request, CreateAppInstanceAction $action): JsonResponse
    {
        $result = $action->execute($request->payload());

        return response()->json(
            [
                'data' => AppInstanceData::fromModel($result['appInstance'])->toArray(),
                'meta' => $this->meta($request),
            ],
            $result['created'] ? 201 : 200,
        );
    }

    #[RequiresNodeAccess(ServingNode::InstanceOwning)]
    public function show(Request $request, AppInstance $instance, ShowAppInstanceAction $action): JsonResponse
    {
        return response()->json([
            'data' => AppInstanceData::fromModel($action->handle($instance))->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::InstanceOwning)]
    public function destroy(
        RemoveAppInstanceRequest $request,
        AppInstance $instance,
        RemoveAppInstanceAction $action,
    ): JsonResponse {
        return response()->json([
            'data' => AppInstanceData::fromModel($action->execute($instance, $request->discardSource()))->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    /** @return array{request_id: string} */
    private function meta(Request $request): array
    {
        return ['request_id' => $request->attributes->getString('orbit.request_id')];
    }
}
