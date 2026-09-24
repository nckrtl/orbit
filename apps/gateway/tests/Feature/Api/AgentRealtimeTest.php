<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;

describe('agent realtime endpoints', function (): void {
    beforeEach(function (): void {
        $this->node = Node::query()->create([
            'name' => 'agent-node',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'public_ssh_host' => '192.0.2.31',
            'wireguard_ip' => '10.44.0.31',
            'ssh_host_fingerprint' => 'SHA256:test',
        ]);
        $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    });

    it('returns the Reverb serving address', function (): void {
        [, $credentials] = activate_websocket_role($this->node);

        $this->getJson('/api/v1/agent/realtime')->assertOk()
            ->assertJsonPath('data.url', 'wss://reverb.orbit')
            ->assertJsonPath('data.address', $this->node->wireguard_ip)
            ->assertJsonPath('data.key', $credentials->appKey)
            ->assertJsonPath('data.channel', "presence-node.{$this->node->id}")
            ->assertJsonPath('data.member', "agent.{$this->node->id}");
    });

    it('returns an eligibility error for an unmanaged node', function (): void {
        $this->node->update(['platform' => 'windows']);
        $this->getJson('/api/v1/agent/realtime')->assertForbidden()->assertJsonPath('error.code', 'agent.node_ineligible');
    });

    it('returns null connection values when websocket is not active', function (): void {
        $this->getJson('/api/v1/agent/realtime')->assertOk()
            ->assertJsonPath('data.url', null)->assertJsonPath('data.address', null)->assertJsonPath('data.key', null);
    });

    it('signs the agent presence membership with valid Pusher HMAC', function (): void {
        [, $credentials] = activate_websocket_role($this->node);
        $channel = "presence-node.{$this->node->id}";
        $response = $this->postJson('/api/v1/agent/broadcasting/auth', [
            'socket_id' => '123.456', 'channel_name' => $channel, 'version' => '1.2.3',
        ])->assertOk();
        $channelData = $response->json('channel_data');

        expect($channelData)->toBeJson()
            ->and($response->json('auth'))->toBe($credentials->appKey.':'.hash_hmac('sha256', '123.456:'.$channel.':'.$channelData, $credentials->appSecret))
            ->and(json_decode($channelData, true))->toBe([
                'user_id' => "agent.{$this->node->id}",
                'user_info' => ['kind' => 'agent', 'node_id' => $this->node->id, 'version' => '1.2.3'],
            ]);
    });

    it('rejects other channels and ineligible nodes', function (): void {
        activate_websocket_role($this->node);
        $this->postJson('/api/v1/agent/broadcasting/auth', ['socket_id' => '1.2', 'channel_name' => 'presence-node.999'])
            ->assertForbidden()->assertJsonPath('error.code', 'agent.channel_forbidden');

        $this->node->update(['platform' => 'windows']);
        $this->postJson('/api/v1/agent/broadcasting/auth', ['socket_id' => '1.2', 'channel_name' => "presence-node.{$this->node->id}"])
            ->assertForbidden()->assertJsonPath('error.code', 'agent.node_ineligible');
    });

    it('rejects invalid socket ids with 422', function (): void {
        activate_websocket_role($this->node);
        $this->postJson('/api/v1/agent/broadcasting/auth', [
            'socket_id' => 'invalid', 'channel_name' => "presence-node.{$this->node->id}",
        ])->assertUnprocessable();
    });

    it('requires a known active peer', function (): void {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.90']);
        $this->getJson('/api/v1/agent/realtime')->assertForbidden()->assertJsonPath('error.code', 'peer.identity_unknown');
    });

    it('returns not found when agent auth has no websocket connection', function (): void {
        $this->postJson('/api/v1/agent/broadcasting/auth', [
            'socket_id' => '1.2', 'channel_name' => "presence-node.{$this->node->id}",
        ])->assertNotFound()->assertJsonPath('error.code', 'realtime.not_configured');
    });
});
