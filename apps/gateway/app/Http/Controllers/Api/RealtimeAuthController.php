<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Broadcasting\PresenceChannelSigner;
use App\Domain\Broadcasting\RealtimeConnection;
use App\Domain\Shared\ResourceOperationException;
use App\Http\Authorization\RequiresNodeAccess;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\RealtimeAuthRequest;
use App\Models\Node;
use Illuminate\Support\Facades\Broadcast;

/** Authorises Gateway-accessible realtime subscriptions. */
#[RequiresNodeAccess(ServingNode::Gateway)]
final class RealtimeAuthController extends Controller
{
    public function authenticate(RealtimeAuthRequest $request, RealtimeConnection $realtime, PresenceChannelSigner $signer): mixed
    {
        $connection = $realtime->resolve();

        if ($connection === null) {
            throw new ResourceOperationException('realtime.not_configured', 'Realtime is not configured.', 404);
        }

        $channel = $request->channelName();

        if (str_starts_with($channel, 'presence-node.')) {
            if (preg_match('/^presence-node\\.[0-9]+$/', $channel) !== 1) {
                throw new ResourceOperationException('broadcast.channel_forbidden', 'Channel is not authorized.', 403);
            }

            $peer = $request->user();
            if (! $peer instanceof Node) {
                throw new ResourceOperationException('peer.identity_unknown', 'Active WireGuard peer identity required.', 403);
            }

            $viewerNodeId = $this->nodeId($peer);

            return response()->json($signer->sign($request->socketId(), $channel, $connection, 'viewer.'.$request->socketId(), [
                'kind' => 'viewer',
                'node_id' => $viewerNodeId,
            ]));
        }

        // A live log stream's channel is signed only by the response that opens the stream (ADR 0153).
        if (str_starts_with($channel, 'presence-') || str_starts_with($channel, 'private-log-stream.')) {
            throw new ResourceOperationException('broadcast.channel_forbidden', 'Channel is not authorized.', 403);
        }

        $realtime->configureBroadcasting();
        $realtime->registerChannelAuthorizers();

        return Broadcast::auth($request);
    }

    private function nodeId(Node $node): int
    {
        return (int) $node->getKey();
    }
}
