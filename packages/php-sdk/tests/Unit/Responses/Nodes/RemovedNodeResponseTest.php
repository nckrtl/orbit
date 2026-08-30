<?php

declare(strict_types=1);

use Orbit\Sdk\Responses\Nodes\RemovedNodeResponse;

describe(RemovedNodeResponse::class, function (): void {
    it('maps the ordinary gateway removal payload with default degradation fields', function (): void {
        $response = RemovedNodeResponse::fromGatewayData([
            'id' => 12,
            'name' => 'operator',
            'removed' => true,
            'wireguard_peer_removed' => true,
            'dns_records_removed' => true,
            'degradation' => null,
            'roles_shed' => [],
            'retained_on_node' => [],
            'follow_up' => null,
        ], '0198e15d-16c4-7855-8eb2-182b53ad28ba');

        expect($response->toArray())->toBe([
            'id' => 12,
            'name' => 'operator',
            'removed' => true,
            'wireguard_peer_removed' => true,
            'dns_records_removed' => true,
            'degradation' => null,
            'roles_shed' => [],
            'retained_on_node' => [],
            'follow_up' => null,
            'request_id' => '0198e15d-16c4-7855-8eb2-182b53ad28ba',
        ]);
    });

    it('maps the degraded gateway removal payload', function (): void {
        $response = RemovedNodeResponse::fromGatewayData([
            'id' => 3,
            'name' => 'app-prod',
            'removed' => true,
            'wireguard_peer_removed' => true,
            'dns_records_removed' => true,
            'degradation' => 'unreachable',
            'roles_shed' => ['app-prod'],
            'retained_on_node' => [
                'Caddy site configuration and certificates for the app-prod role',
            ],
            'follow_up' => 'Run the node-local Metrics cleanup on the node once it boots, or discard the node.',
        ], '0198e15d-16c4-7855-8eb2-182b53ad28ba');

        expect($response->toArray())->toBe([
            'id' => 3,
            'name' => 'app-prod',
            'removed' => true,
            'wireguard_peer_removed' => true,
            'dns_records_removed' => true,
            'degradation' => 'unreachable',
            'roles_shed' => ['app-prod'],
            'retained_on_node' => [
                'Caddy site configuration and certificates for the app-prod role',
            ],
            'follow_up' => 'Run the node-local Metrics cleanup on the node once it boots, or discard the node.',
            'request_id' => '0198e15d-16c4-7855-8eb2-182b53ad28ba',
        ]);
    });

    it('degrades missing or garbage keys to their defaults instead of throwing', function (): void {
        $response = RemovedNodeResponse::fromGatewayData([
            'id' => 'not-an-id',
            'name' => 12,
            'removed' => 'true',
            'wireguard_peer_removed' => 'true',
            'dns_records_removed' => 1,
            'degradation' => 42,
            'roles_shed' => 'not-a-list',
            'retained_on_node' => ['fine', 7, null],
            'follow_up' => false,
        ], '0198e15d-16c4-7855-8eb2-182b53ad28ba');

        expect($response->toArray())->toBe([
            'id' => 0,
            'name' => '',
            'removed' => false,
            'wireguard_peer_removed' => false,
            'dns_records_removed' => false,
            'degradation' => null,
            'roles_shed' => [],
            'retained_on_node' => ['fine'],
            'follow_up' => null,
            'request_id' => '0198e15d-16c4-7855-8eb2-182b53ad28ba',
        ]);
    });

    it('preserves the constructor contract', function (): void {
        $response = new RemovedNodeResponse(
            id: 7,
            name: 'app-dev',
            removed: true,
            wireguardPeerRemoved: true,
            dnsRecordsRemoved: true,
            degradation: null,
            rolesShed: [],
            retainedOnNode: [],
            followUp: null,
            requestId: '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
        );

        expect($response->id)
            ->toBe(7)
            ->and($response->name)
            ->toBe('app-dev')
            ->and($response->removed)
            ->toBeTrue()
            ->and($response->wireguardPeerRemoved)
            ->toBeTrue()
            ->and($response->dnsRecordsRemoved)
            ->toBeTrue()
            ->and($response->degradation)
            ->toBeNull()
            ->and($response->rolesShed)
            ->toBeEmpty()
            ->and($response->retainedOnNode)
            ->toBeEmpty()
            ->and($response->followUp)
            ->toBeNull()
            ->and($response->requestId)
            ->toBe('0198e15c-bf97-7c23-8f1f-61b8fe67a844');
    });
});
