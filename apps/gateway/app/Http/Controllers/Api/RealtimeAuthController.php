<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domain\Broadcasting\RealtimeConnection;
use App\Domain\Nodes\ManagedNodeEligibility;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Models\Node;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;

/**
 * Authorises a Reverb subscription to the private `orbit` channel. Any active WireGuard
 * peer with Gateway access may subscribe; the channel rules live in routes/channels.php.
 */
#[RequiresNodeAccess(ServingNode::Gateway)]
final class RealtimeAuthController extends Controller
{
    public function authenticateAgent(Request $request, RealtimeConnection $realtime, ManagedNodeEligibility $eligibility): mixed
    {
        $peer = $request->user();

        if (! $peer instanceof Node || ! $eligibility->allows($peer)) {
            return response()->json(['error' => ['code' => 'agent.node_ineligible', 'message' => 'Node is not eligible for an agent.', 'details' => []]], 403);
        }

        $nodeId = $this->nodeId($peer);
        $channel = "presence-node.{$nodeId}";

        if ($request->input('channel_name') !== $channel) {
            return response()->json(['error' => ['code' => 'agent.channel_forbidden', 'message' => 'Agent may only join its own presence channel.', 'details' => []]], 403);
        }

        $connection = $realtime->resolve();

        if ($connection === null) {
            return new JsonResponse(['message' => 'Realtime is not configured.'], 404);
        }

        return $this->presenceResponse($request, $realtime, "agent.{$nodeId}", [
            'kind' => 'agent',
            'node_id' => $nodeId,
            'version' => $request->input('version'),
        ]);
    }

    public function authenticate(Request $request, RealtimeConnection $realtime): mixed
    {
        if (! $realtime->configureBroadcasting()) {
            return new JsonResponse(['message' => 'Realtime is not configured.'], 404);
        }

        $channel = $request->input('channel_name');

        if (is_string($channel) && str_starts_with($channel, 'presence-node.')) {
            if (preg_match('/^presence-node\\.[0-9]+$/', $channel) !== 1) {
                return response()->json(['error' => ['code' => 'broadcast.channel_forbidden', 'message' => 'Channel is not authorized.', 'details' => []]], 403);
            }

            return $this->presenceResponse($request, $realtime, "viewer.{$request->input('socket_id')}", [
                'kind' => 'viewer',
                'node_id' => substr($channel, strlen('presence-node.')),
            ]);
        }

        if (is_string($channel) && str_starts_with($channel, 'presence-')) {
            return response()->json(['error' => ['code' => 'broadcast.channel_forbidden', 'message' => 'Channel is not authorized.', 'details' => []]], 403);
        }

        $realtime->registerChannelAuthorizers();

        return Broadcast::auth($request);
    }

    private function nodeId(Node $node): int
    {
        return (int) $node->getKey();
    }

    /** @param array{kind: string, node_id: mixed, version?: mixed} $userInfo */
    private function presenceResponse(Request $request, RealtimeConnection $realtime, string $member, array $userInfo): JsonResponse
    {
        $connection = $realtime->resolve();
        $channel = $request->string('channel_name')->toString();
        $socketId = $request->string('socket_id')->toString();
        $channelData = json_encode(['user_id' => $member, 'user_info' => $userInfo], JSON_THROW_ON_ERROR);
        $auth = $connection->key.':'.hash_hmac('sha256', "{$socketId}:{$channel}:{$channelData}", $connection->secret);

        return response()->json(['auth' => $auth, 'channel_data' => $channelData]);
    }
}
