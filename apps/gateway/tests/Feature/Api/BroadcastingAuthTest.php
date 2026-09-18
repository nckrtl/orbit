<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;

beforeEach(function (): void {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'test-key',
        'broadcasting.connections.reverb.secret' => 'test-secret',
        'broadcasting.connections.reverb.app_id' => 'test-app',
    ]);

    // The app boots with BROADCAST_CONNECTION=null in tests, so routes/channels.php
    // registered the `orbit` channel against the null connection. Re-require it now
    // that the default connection is reverb, so the channel is registered there too.
    require base_path('routes/channels.php');
});

describe('POST /api/v1/broadcasting/auth', function (): void {
    it('authorizes the private orbit channel for an active WireGuard peer', function (): void {
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
            'socket_id' => '1234.1234',
            'channel_name' => 'private-orbit',
        ]);

        $response->assertOk();
        expect($response->json('auth'))->toBeString()->not->toBe('');
    });

    it('refuses an unauthenticated caller with 403', function (): void {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9']);

        $response = $this->postJson('/api/v1/broadcasting/auth', [
            'socket_id' => '1234.1234',
            'channel_name' => 'private-orbit',
        ]);

        $response->assertForbidden();
        expect($response->json('error.code'))->toBe('peer.identity_unknown');
    });

    it('refuses a caller whose peer node is not active', function (): void {
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
