<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use Illuminate\Testing\TestResponse;

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
        $this->node->forceFill(['agent_secret_hash' => hash('sha256', 'agent-node-secret')])->save();
        $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip])->withToken('agent-node-secret');
    });

    it('returns the Reverb serving address', function (): void {
        [, $credentials] = activate_websocket_role($this->node);

        $this->getJson('/api/v1/agent/realtime')->assertOk()
            ->assertJsonPath('data.url', 'wss://reverb.orbit')
            ->assertJsonPath('data.address', $this->node->wireguard_ip)
            ->assertJsonPath('data.key', $credentials->appKey)
            ->assertJsonPath('data.channel', "presence-node.{$this->node->id}")
            ->assertJsonPath('data.log_channel', "presence-node-logs.{$this->node->id}")
            ->assertJsonPath('data.member', "agent.{$this->node->id}");
    });

    it('signs the agent membership of its own log channel only', function (): void {
        [, $credentials] = activate_websocket_role($this->node);
        $channel = "presence-node-logs.{$this->node->id}";
        $response = $this->postJson('/api/v1/agent/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => $channel, 'version' => '0.3.0'])->assertOk();

        expect($response->json('auth'))->toBe($credentials->appKey.':'.hash_hmac('sha256', '123.456:'.$channel.':'.$response->json('channel_data'), $credentials->appSecret))
            ->and(json_decode($response->json('channel_data'), true)['user_id'])->toBe("agent.{$this->node->id}");

        foreach (['presence-node-logs.999', 'private-log-stream.'.str_repeat('a', 32), "presence-node-logs.{$this->node->id}x"] as $other) {
            $this->postJson('/api/v1/agent/broadcasting/auth', ['socket_id' => '1.2', 'channel_name' => $other])
                ->assertForbidden()->assertJsonPath('error.code', 'agent.channel_forbidden');
        }
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

/** Calls one agent endpoint with the given bearer token, or none. */
function agent_endpoint_call(mixed $test, string $endpoint, ?string $token): TestResponse
{
    $headers = $token === null ? [] : ['Authorization' => 'Bearer '.$token];

    return match ($endpoint) {
        'realtime' => $test->getJson('/api/v1/agent/realtime', $headers),
        'workspaces' => $test->getJson('/api/v1/agent/workspaces', $headers),
        'log-streams' => $test->getJson('/api/v1/agent/log-streams', $headers),
        'auth' => $test->postJson('/api/v1/agent/broadcasting/auth', [
            'socket_id' => '123.456', 'channel_name' => 'presence-node.'.$test->node->id,
        ], $headers),
    };
}

describe('the agent secret', function (): void {
    beforeEach(function (): void {
        $this->secret = bin2hex(random_bytes(32));
        $this->node = Node::query()->forceCreate([
            'name' => 'secret-node',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'public_ssh_host' => '192.0.2.32',
            'wireguard_ip' => '10.44.0.32',
            'ssh_host_fingerprint' => 'SHA256:test',
            'agent_secret_hash' => hash('sha256', $this->secret),
        ]);
        $this->other = bin2hex(random_bytes(32));
        Node::query()->forceCreate([
            'name' => 'other-node',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'public_ssh_host' => '192.0.2.33',
            'wireguard_ip' => '10.44.0.33',
            'ssh_host_fingerprint' => 'SHA256:test',
            'agent_secret_hash' => hash('sha256', $this->other),
        ]);
        activate_websocket_role($this->node);
        $this->withServerVariables(['REMOTE_ADDR' => $this->node->wireguard_ip]);
    });

    it('refuses a request without the secret', function (string $endpoint): void {
        agent_endpoint_call($this, $endpoint, null)->assertUnauthorized()->assertJsonPath('error.code', 'agent.secret_required');
    })->with(['realtime', 'auth', 'workspaces', 'log-streams']);

    it('refuses a wrong secret and another Node\'s secret', function (string $endpoint): void {
        agent_endpoint_call($this, $endpoint, str_repeat('0', 64))->assertForbidden()->assertJsonPath('error.code', 'agent.secret_invalid');
        agent_endpoint_call($this, $endpoint, $this->other)->assertForbidden()->assertJsonPath('error.code', 'agent.secret_invalid');
        // The stored hash itself is not a secret the endpoint accepts.
        agent_endpoint_call($this, $endpoint, (string) $this->node->agent_secret_hash)->assertForbidden()->assertJsonPath('error.code', 'agent.secret_invalid');
    })->with(['realtime', 'auth', 'workspaces', 'log-streams']);

    it('accepts the Node\'s own secret', function (string $endpoint): void {
        agent_endpoint_call($this, $endpoint, $this->secret)->assertOk();
    })->with(['realtime', 'auth', 'workspaces', 'log-streams']);

    it('never signs a membership for another Node from its secret', function (): void {
        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.33']);

        agent_endpoint_call($this, 'auth', $this->secret)->assertForbidden()->assertJsonPath('error.code', 'agent.secret_invalid');
    });

    it('refuses a Node that must send a secret but has none recorded', function (): void {
        $this->node->forceFill(['agent_secret_hash' => null, 'agent_secret_exempt' => false])->save();

        agent_endpoint_call($this, 'realtime', null)->assertUnauthorized()->assertJsonPath('error.code', 'agent.secret_required');
        agent_endpoint_call($this, 'realtime', $this->secret)->assertForbidden()->assertJsonPath('error.code', 'agent.secret_invalid');
    });

    it('accepts an exempt Node without a secret until it has one', function (): void {
        $this->node->forceFill(['agent_secret_hash' => null, 'agent_secret_exempt' => true])->save();
        agent_endpoint_call($this, 'realtime', null)->assertOk();

        $this->node->forceFill(['agent_secret_hash' => hash('sha256', $this->secret), 'agent_secret_exempt' => false])->save();
        agent_endpoint_call($this, 'realtime', null)->assertUnauthorized();
    });

    it('never returns the secret hash', function (): void {
        $body = agent_endpoint_call($this, 'realtime', $this->secret)->assertOk()->getContent();

        expect($body)->not->toContain((string) $this->node->agent_secret_hash)
            ->and($this->node->fresh()?->toArray())->not->toHaveKey('agent_secret_hash');
    });
});
