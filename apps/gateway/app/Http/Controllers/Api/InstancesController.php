<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Instances\CreateInstanceAction;
use App\Actions\Instances\ListInstancesAction;
use App\Actions\Instances\RegisterInstanceAction;
use App\Actions\Instances\RemoveInstanceAction;
use App\Actions\Instances\RenameInstanceAction;
use App\Actions\Instances\RunInstanceSetupAction;
use App\Actions\Instances\ShowInstanceAction;
use App\Actions\Instances\UpdateInstanceAction;
use App\Data\Instances\InstanceData;
use App\Data\Instances\InstanceRegistrationData;
use App\Data\Instances\InstanceRemovalData;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Instances\RegisterInstanceRequest;
use App\Http\Requests\Instances\RemoveInstanceRequest;
use App\Http\Requests\Instances\RenameInstanceRequest;
use App\Http\Requests\Instances\StoreInstanceRequest;
use App\Http\Requests\Instances\UpdateInstanceRequest;
use App\Models\Instance;
use App\Models\Node;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class InstancesController extends Controller
{
    #[RequiresNodeAccess(ServingNode::Collection)]
    public function index(Request $request, ListInstancesAction $action): JsonResponse
    {
        $consumer = $request->user();
        assert($consumer instanceof Node, description: 'Authenticated peer must be a Node.');

        return response()->json([
            'data' => $action
                ->handle($consumer)
                ->map(static fn (Instance $row): array => InstanceData::fromModel($row)->toArray())
                ->values()
                ->all(),
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::InstanceCreation)]
    public function store(StoreInstanceRequest $request, CreateInstanceAction $action): JsonResponse
    {
        $result = $action->execute($request->payload());

        return response()->json(
            [
                'data' => InstanceData::fromModel($result['instance'])->toArray(),
                'meta' => $this->meta($request),
            ],
            $result['created'] ? 201 : 200,
        );
    }

    #[RequiresNodeAccess(ServingNode::Caller)]
    public function register(RegisterInstanceRequest $request, RegisterInstanceAction $action): JsonResponse
    {
        $caller = $request->user();
        assert($caller instanceof Node);
        $result = $action->execute($caller, $request->payload());
        $request->attributes->set('orbit.instance_registration', $result['primary']);
        $request->route()?->setParameter('instance', $result['primary']);

        return response()->json(
            [
                'data' => InstanceRegistrationData::fromModels(
                    $result['project'],
                    $result['primary'],
                    $result['instances'],
                )->toArray(),
                'meta' => $this->meta($request),
            ],
        );
    }

    #[RequiresNodeAccess(ServingNode::InstanceOwning)]
    public function show(Request $request, Instance $instance, ShowInstanceAction $action): JsonResponse
    {
        return response()->json([
            'data' => InstanceData::fromModel($action->handle($instance))->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::InstanceOwning)]
    public function update(
        UpdateInstanceRequest $request,
        Instance $instance,
        UpdateInstanceAction $action,
    ): JsonResponse {
        return response()->json([
            'data' => InstanceData::fromModel($action->execute($instance, $request->branch()))->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::InstanceOwning)]
    public function rename(RenameInstanceRequest $request, Instance $instance, RenameInstanceAction $action): JsonResponse
    {
        return response()->json([
            'data' => InstanceData::fromModel($action->execute($instance, $request->payload()))->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::InstanceOwning)]
    public function setup(Request $request, Instance $instance, RunInstanceSetupAction $action): JsonResponse
    {
        return response()->json([
            'data' => InstanceData::fromModel($action->execute($instance))->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::InstanceOwning)]
    public function destroy(
        RemoveInstanceRequest $request,
        Instance $instance,
        RemoveInstanceAction $action,
    ): JsonResponse {
        return response()->json([
            'data' => InstanceRemovalData::fromModel($action->execute($instance, $request->force()))->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    /** @return array{request_id: string} */
    private function meta(Request $request): array
    {
        return ['request_id' => $request->attributes->getString('orbit.request_id')];
    }
}
