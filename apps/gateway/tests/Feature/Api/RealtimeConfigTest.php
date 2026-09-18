<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;

beforeEach(function (): void {
    $this->node = Node::query()->create([
        'name' => 'app-dev',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.20',
        'wireguard_ip' => '10.44.0.3',
    ]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
});

describe('GET /api/v1/realtime', function (): void {
    it('returns the Reverb connection details when broadcasting is configured', function (): void {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.options.host' => 'reverb.orbit',
        ]);

        $this->getJson('/api/v1/realtime')
            ->assertOk()
            ->assertJsonPath('data.url', 'wss://reverb.orbit')
            ->assertJsonPath('data.key', 'test-key')
            ->assertJsonPath('data.channel', 'orbit')
            ->assertJsonStructure(['meta' => ['request_id']]);
    });

    it('returns null connection details when broadcasting is not configured', function (): void {
        config(['broadcasting.default' => 'null']);

        $this->getJson('/api/v1/realtime')
            ->assertOk()
            ->assertJsonPath('data.url', null)
            ->assertJsonPath('data.key', null)
            ->assertJsonPath('data.channel', 'orbit');
    });

    it('returns null connection details when the Reverb host or key is missing', function (): void {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => null,
            'broadcasting.connections.reverb.options.host' => 'reverb.orbit',
        ]);

        $this->getJson('/api/v1/realtime')
            ->assertOk()
            ->assertJsonPath('data.url', null)
            ->assertJsonPath('data.key', null);
    });

    it('refuses an unauthenticated caller with 403', function (): void {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9']);

        $this->getJson('/api/v1/realtime')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'peer.identity_unknown');
    });
});
