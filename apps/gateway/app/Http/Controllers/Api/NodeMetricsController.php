<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Nodes\ShowNodeMetricsAction;
use App\Data\Nodes\NodeMetricsData;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Models\Node;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class NodeMetricsController extends Controller
{
    #[RequiresNodeAccess(ServingNode::Target)]
    public function show(Request $request, Node $node, ShowNodeMetricsAction $action): JsonResponse
    {
        return response()->json([
            'data' => [
                'node_id' => $node->id,
                'node_name' => $node->name,
                ...NodeMetricsData::fromRaw($action->execute($node))->toArray(),
            ],
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }
}
