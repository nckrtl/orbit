<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\Broadcasting\PresenceChannelSigner;
use App\Actions\Tasks\ListAgentWorkspacesAction;
use App\Data\Tasks\AgentWorkspaceData;
use App\Domain\Broadcasting\RealtimeConnection;
use App\Domain\Logs\LogStream;
use App\Domain\Logs\LogStreamStore;
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
                'address' => $connection?->resolveAddress,
                'key' => $connection?->key,
                'channel' => "presence-node.{$id}",
                'log_channel' => "presence-node-logs.{$id}",
                'member' => "agent.{$id}",
            ],
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }

    /** The task checkouts the caller's agent watches (ADR 0151). */
    public function workspaces(Request $request, ManagedNodeEligibility $eligibility, ListAgentWorkspacesAction $action): JsonResponse
    {
        $node = $this->peer($request);
        $this->ensureEligible($node, $eligibility);

        return response()->json([
            'data' => array_map(static fn (AgentWorkspaceData $workspace): array => $workspace->toArray(), $action->execute($node)),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }

    /**
     * The live log streams whose source is on the caller's Node (ADR 0153). The agent reads only what
     * this list names; a `log-streams.changed` event only prompts it to ask again.
     */
    public function logStreams(Request $request, ManagedNodeEligibility $eligibility, LogStreamStore $streams): JsonResponse
    {
        $node = $this->peer($request);
        $this->ensureEligible($node, $eligibility);

        return response()->json([
            'data' => array_map(static fn (LogStream $stream): array => [
                'id' => $stream->id,
                'lines' => $stream->lines,
                'source' => $stream->source->toArray(),
            ], $streams->forNode((int) $node->getKey())),
            'meta' => ['request_id' => $request->attributes->getString('orbit.request_id')],
        ]);
    }

    public function authenticate(AgentRealtimeAuthRequest $request, RealtimeConnection $realtime, ManagedNodeEligibility $eligibility, PresenceChannelSigner $signer): JsonResponse
    {
        $node = $this->peer($request);
        $this->ensureEligible($node, $eligibility);
        $id = (int) $node->getKey();
        $channel = $request->channelName();

        // The Node's own presence channel, and from agent 0.3.0 its own log channel (ADR 0153).
        if (! in_array($channel, ["presence-node.{$id}", "presence-node-logs.{$id}"], strict: true)) {
            throw new ResourceOperationException('agent.channel_forbidden', 'Agent may only join its own presence channels.', 403);
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
