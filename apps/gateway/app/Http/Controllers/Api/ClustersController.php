<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Clusters\AttachClusterNodeAction;
use App\Actions\Clusters\ClearClusterRouterAction;
use App\Actions\Clusters\CreateClusterAction;
use App\Actions\Clusters\DetachClusterNodeAction;
use App\Actions\Clusters\ListClustersAction;
use App\Actions\Clusters\RemoveClusterAction;
use App\Actions\Clusters\SetClusterRouterAction;
use App\Actions\Clusters\ShowClusterAction;
use App\Actions\Clusters\UpdateClusterAction;
use App\Data\Clusters\ClusterData;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clusters\ClusterDestructiveRequest;
use App\Http\Requests\Clusters\StoreClusterRequest;
use App\Http\Requests\Clusters\UpdateClusterRequest;
use App\Models\Cluster;
use App\Models\Node;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ClustersController extends Controller
{
    #[RequiresNodeAccess(ServingNode::Collection)]
    public function index(Request $request, ListClustersAction $action): JsonResponse
    {
        /** @mago-expect analysis:mixed-assignment The authenticated peer resolver returns a Node. */
        $consumer = $request->user();
        assert($consumer instanceof Node, description: 'Authenticated peer must be a Node.');

        return response()->json([
            'data' => $action
                ->handle($consumer)
                ->map(static fn (Cluster $cluster): array => ClusterData::fromModel($cluster)->toArray())
                ->values()
                ->all(),
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::Gateway)]
    public function store(StoreClusterRequest $request, CreateClusterAction $action): JsonResponse
    {
        $cluster = $action->execute($request->payload());

        return response()->json([
            'data' => ClusterData::fromModel($cluster)->toArray(),
            'meta' => $this->meta($request),
        ], 201);
    }

    #[RequiresNodeAccess(ServingNode::ClusterOwning)]
    public function show(Request $request, Cluster $cluster, ShowClusterAction $action): JsonResponse
    {
        return response()->json([
            'data' => ClusterData::fromModel($action->handle($cluster))->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::ClusterOwning)]
    public function update(
        UpdateClusterRequest $request,
        Cluster $cluster,
        UpdateClusterAction $action,
    ): JsonResponse {
        return response()->json([
            'data' => ClusterData::fromModel($action->execute($cluster, $request->payload()))->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::ClusterOwning)]
    public function destroy(Request $request, Cluster $cluster, RemoveClusterAction $action): JsonResponse
    {
        return response()->json([
            'data' => ClusterData::fromModel($action->execute($cluster))->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::Target)]
    public function attach(
        Request $request,
        Cluster $cluster,
        Node $node,
        AttachClusterNodeAction $action,
    ): JsonResponse {
        return response()->json([
            'data' => ClusterData::fromModel($action->execute($cluster, $node))->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::Target)]
    public function detach(
        ClusterDestructiveRequest $request,
        Cluster $cluster,
        Node $node,
        DetachClusterNodeAction $action,
    ): JsonResponse {
        return response()->json([
            'data' => ClusterData::fromModel($action->execute($cluster, $node))->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::Target)]
    public function setRouter(
        Request $request,
        Cluster $cluster,
        Node $node,
        SetClusterRouterAction $action,
    ): JsonResponse {
        return response()->json([
            'data' => ClusterData::fromModel($action->execute($cluster, $node))->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    #[RequiresNodeAccess(ServingNode::ClusterOwning)]
    public function clearRouter(
        ClusterDestructiveRequest $request,
        Cluster $cluster,
        ClearClusterRouterAction $action,
    ): JsonResponse {
        return response()->json([
            'data' => ClusterData::fromModel($action->execute($cluster))->toArray(),
            'meta' => $this->meta($request),
        ]);
    }

    /** @return array{request_id: string} */
    private function meta(Request $request): array
    {
        return ['request_id' => $request->attributes->getString('orbit.request_id')];
    }
}
