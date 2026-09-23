<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;

describe('POST /api/v1/broadcasting/auth', function (): void {
    it('authorizes the private orbit channel for an active WireGuard peer', function (): void {
        [, $credentials] = activate_websocket_role();

        // The channel is Gateway-scoped, so the subscriber is the gateway peer.
        $node = $this->markAsGateway(Node::query()->create([
            'name' => 'gateway',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'public_ssh_host' => '192.0.2.2',
            'wireguard_ip' => '10.44.0.2',
        ]));
        $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);

        $response = $this->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '1.1',
            'channel_name' => 'private-orbit',
        ]);

        $response->assertOk();
        expect($response->json('auth'))->toBe(
            $credentials->appKey.':'.hash_hmac('sha256', '1.1:private-orbit', $credentials->appSecret),
        );
    });

    it('signs presence-node subscriptions as viewers and refuses agent member ids', function (): void {
        [, $credentials] = activate_websocket_role();
        $node = $this->markAsGateway(Node::query()->create([
            'name' => 'gateway', 'status' => LifecycleStatus::Active, 'platform' => 'linux',
            'public_ssh_host' => '192.0.2.2', 'wireguard_ip' => '10.44.0.2',
        ]));
        $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
        $channel = 'presence-node.12';
        $response = $this->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '1.1', 'channel_name' => $channel,
        ])->assertOk();
        $channelData = $response->json('channel_data');

        expect($response->json('auth'))->toBe($credentials->appKey.':'.hash_hmac('sha256', '1.1:'.$channel.':'.$channelData, $credentials->appSecret))
            ->and(json_decode($channelData, true))->toBe([
                'user_id' => 'viewer.1.1',
                'user_info' => ['kind' => 'viewer', 'node_id' => $node->id],
            ])
            ->and(json_decode($channelData, true)['user_id'])->toBe('viewer.1.1');

        $withAgentInput = $this->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '1.1', 'channel_name' => 'presence-node.12',
            'user_id' => 'agent.12', 'member' => 'agent.12', 'kind' => 'agent',
            'channel_data' => json_encode(['user_id' => 'agent.12', 'user_info' => ['kind' => 'agent']]),
        ])->assertOk()->json('channel_data');
        expect(json_decode($withAgentInput, true))->toBe([
            'user_id' => 'viewer.1.1',
            'user_info' => ['kind' => 'viewer', 'node_id' => $node->id],
        ]);
    });

    it('refuses malformed presence-node and other presence channels', function (): void {
        activate_websocket_role();
        $node = $this->markAsGateway(Node::query()->create([
            'name' => 'gateway', 'status' => LifecycleStatus::Active, 'platform' => 'linux',
            'public_ssh_host' => '192.0.2.2', 'wireguard_ip' => '10.44.0.2',
        ]));
        $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);

        foreach (['presence-node.12x', 'presence-node.', 'presence-orbit'] as $channel) {
            $this->postJson('/api/v1/broadcasting/auth', ['socket_id' => '1.1', 'channel_name' => $channel])
                ->assertForbidden()->assertJsonPath('error.code', 'broadcast.channel_forbidden');
        }
    });

    it('requires Gateway access for viewer presence subscriptions', function (): void {
        [, $credentials] = activate_websocket_role();
        $node = Node::query()->create([
            'name' => 'peer-no-edge', 'status' => LifecycleStatus::Active, 'platform' => 'linux',
            'public_ssh_host' => '192.0.2.3', 'wireguard_ip' => '10.44.0.3',
        ]);
        $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
        $this->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '1.1', 'channel_name' => 'presence-node.12',
        ])->assertForbidden()->assertJsonPath('error.code', 'node_access.required');
    });

    it('answers 404 when no websocket role is active', function (): void {
        $node = $this->markAsGateway(Node::query()->create([
            'name' => 'gateway',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'public_ssh_host' => '192.0.2.2',
            'wireguard_ip' => '10.44.0.2',
        ]));
        $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);

        $response = $this->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '1234.1234',
            'channel_name' => 'private-orbit',
        ]);

        $response->assertNotFound();
    });

    it('refuses an unauthenticated caller with 403', function (): void {
        activate_websocket_role();
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9']);

        $response = $this->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '1234.1234',
            'channel_name' => 'private-orbit',
        ]);

        $response->assertForbidden();
        expect($response->json('error.code'))->toBe('peer.identity_unknown');
    });

    it('refuses a caller whose peer node is not active', function (): void {
        activate_websocket_role();
        $node = Node::query()->create([
            'name' => 'quarantined',
            'status' => LifecycleStatus::Failed,
            'public_ssh_host' => '192.0.2.21',
            'wireguard_ip' => '10.44.0.4',
        ]);
        $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);

        $response = $this->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '1234.1234',
            'channel_name' => 'private-orbit',
        ]);

        $response->assertForbidden();
    });
});
