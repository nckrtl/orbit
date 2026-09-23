<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Broadcasting\ShowRealtimeConfigAction;
use App\Domain\Broadcasting\RealtimeConnection;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Models\Node;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[RequiresNodeAccess(ServingNode::Gateway)]
final class RealtimeConfigController extends Controller
{
    public function agent(Request $request, RealtimeConnection $realtime): JsonResponse
    {
        $peer = $request->user();

        if (! $peer instanceof Node) {
            abort(403);
        }

        $nodeId = $this->nodeId($peer);
        $connection = $realtime->resolve();

        return response()->json([
            'data' => [
                'url' => $connection?->url(),
                'key' => $connection?->key,
                'channel' => "presence-node.{$nodeId}",
                'member' => "agent.{$nodeId}",
            ],
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }

    private function nodeId(Node $node): int
    {
        return (int) $node->getKey();
    }

    public function show(Request $request, ShowRealtimeConfigAction $action): JsonResponse
    {
        return response()->json([
            'data' => $action->execute()->toArray(),
            'meta' => [
                'request_id' => $request->attributes->getString('orbit.request_id'),
            ],
        ]);
    }
}
