<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\AppInstances\CreateAppInstanceAction;
use App\Actions\AppInstances\ListAppInstancesAction;
use App\Actions\AppInstances\RegisterAppInstanceAction;
use App\Actions\AppInstances\RemoveAppInstanceAction;
use App\Actions\AppInstances\ShowAppInstanceAction;
use App\Data\AppInstances\AppInstanceData;
use App\Data\AppInstances\AppInstanceRegistrationData;
use App\Data\AppInstances\AppInstanceRemovalData;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\AppInstances\RegisterAppInstanceRequest;
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

    #[RequiresNodeAccess(ServingNode::Caller)]
    public function register(RegisterAppInstanceRequest $request, RegisterAppInstanceAction $action): JsonResponse
    {
        $caller = $request->user();
        assert($caller instanceof Node);
        $result = $action->execute($caller, $request->payload());
        $request->attributes->set('orbit.app_instance_registration', $result['primary']);
        $request->route()?->setParameter('instance', $result['primary']);

        return response()->json(
            [
                'data' => AppInstanceRegistrationData::fromModels(
                    $result['app'],
                    $result['primary'],
                    $result['instances'],
                )->toArray(),
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
            'data' => AppInstanceRemovalData::fromModel($action->execute($instance, $request->force()))->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    /** @return array{request_id: string} */
    private function meta(Request $request): array
    {
        return ['request_id' => $request->attributes->getString('orbit.request_id')];
    }
}
