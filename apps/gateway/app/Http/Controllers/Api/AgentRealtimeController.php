<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Broadcasting\PresenceChannelSigner;
use App\Domain\Broadcasting\RealtimeConnection;
use App\Domain\Nodes\ManagedNodeEligibility;
use App\Domain\Shared\ResourceOperationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\AgentRealtimeAuthRequest;
use App\Models\Node;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AgentRealtimeController extends Controller
{
    public function show(Request $request, RealtimeConnection $realtime, ManagedNodeEligibility $eligibility): JsonResponse
    {
        $node = $this->peer($request);
        $this->ensureEligible($node, $eligibility);
        $connection = $realtime->resolve();
        $id = (int) $node->getKey();

        return response()->json([
            'data' => [
                'url' => $connection?->url(),
                'key' => $connection?->key,
                'channel' => "presence-node.{$id}",
                'member' => "agent.{$id}",
            ],
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }

    public function authenticate(AgentRealtimeAuthRequest $request, RealtimeConnection $realtime, ManagedNodeEligibility $eligibility, PresenceChannelSigner $signer): JsonResponse
    {
        $node = $this->peer($request);
        $this->ensureEligible($node, $eligibility);
        $channel = 'presence-node.'.(int) $node->getKey();

        if ($request->channelName() !== $channel) {
            throw new ResourceOperationException('agent.channel_forbidden', 'Agent may only join its own presence channel.', 403);
        }

        $connection = $realtime->resolve();

        if ($connection === null) {
            throw new ResourceOperationException('realtime.not_configured', 'Realtime is not configured.', 404);
        }

        return response()->json($signer->sign($request->socketId(), $channel, $connection, 'agent.'.(int) $node->getKey(), [
            'kind' => 'agent',
            'node_id' => (int) $node->getKey(),
            'version' => $request->version(),
        ]));
    }

    private function peer(Request $request): Node
    {
        $peer = $request->user();

        if (! $peer instanceof Node) {
            throw new ResourceOperationException('peer.identity_unknown', 'Active WireGuard peer identity required.', 403);
        }

        return $peer;
    }

    private function ensureEligible(Node $node, ManagedNodeEligibility $eligibility): void
    {
        if (! $eligibility->allows($node)) {
            throw new ResourceOperationException('agent.node_ineligible', 'Node is not eligible for an agent.', 403);
        }
    }
}
