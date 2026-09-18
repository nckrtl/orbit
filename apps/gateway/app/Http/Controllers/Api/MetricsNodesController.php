<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Metrics\ListFleetNodeMetricsAction;
use App\Data\Metrics\FleetNodeMetricsData;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Models\Node;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A dedicated controller, rather than another `MetricsController` method: that controller's
 * `RequiresNodeAccess(ServingNode::Gateway)` class attribute covers every other Metrics action,
 * but this one lists metrics for the caller's own accessible Nodes (`ServingNode::Collection`,
 * the same scope `NodesController::index()` uses), which a class-level Gateway scope cannot mix
 * with.
 */
final class MetricsNodesController extends Controller
{
    #[RequiresNodeAccess(ServingNode::Collection)]
    public function index(Request $request, ListFleetNodeMetricsAction $action): JsonResponse
    {
        $consumer = $request->user();
        assert($consumer instanceof Node, description: 'Authenticated peer must be a Node.');

        $entries = $action->execute($consumer);

        return response()->json([
            'data' => array_map(static fn (FleetNodeMetricsData $entry): array => $entry->toArray(), $entries),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }
}
