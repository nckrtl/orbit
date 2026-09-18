<?php

declare(strict_types=1);

use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Settings\SettingValueProtection;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\WebSocket\WebSocketFootprint;
use App\Models\Node;
use Orbit\Sdk\Requests\Realtime\ShowRealtimeRequest;

/**
 * Records the realtime discovery response that the CLI replays. Data is deterministic on
 * purpose: the caller Node is id 1, and the request id is fixed.
 */
describe('realtime response fixtures', function (): void {
    beforeEach(function (): void {
        $operator = $this->markAsGateway(Node::query()->create([
            'name' => 'gateway',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'public_ssh_host' => '192.0.2.2',
            'wireguard_ip' => '10.44.0.1',
        ]));
        $this->withServerVariables(['REMOTE_ADDR' => $operator->wireguard_ip]);
        $this->withHeader('X-Orbit-Request-Id', fixture_request_id());
    });

    it('records the realtime connection when the websocket role is active', function (): void {
        [$node] = activate_websocket_role();

        // Deterministic fixture: overwrite the generated app key with a fixed
        // value, since the rest of the credentials never reach this response.
        app(SettingRepository::class)->put(
            new SettingScope(SettingScopeType::Node, $node->id),
            WebSocketFootprint::SettingKeyAppKey,
            'orbit-reverb-app-key',
            SettingValueProtection::Plain,
        );

        record_fixture(
            $this->getJson('/api/v1/realtime')->assertOk(),
            'realtime/realtime-show/configured',
            ShowRealtimeRequest::class,
            'GET /api/v1/realtime',
        );
    });

    it('records the realtime connection when no websocket role is active', function (): void {
        record_fixture(
            $this->getJson('/api/v1/realtime')->assertOk(),
            'realtime/realtime-show/unconfigured',
            ShowRealtimeRequest::class,
            'GET /api/v1/realtime',
        );
    });
});
