<?php

declare(strict_types=1);

use Orbit\Sdk\Responses\Clusters\ClusterNodeResponse;
use Orbit\Sdk\Responses\Clusters\ClusterResponse;

describe(ClusterResponse::class, function (): void {
    it('maps the complete typed Cluster contract', function (): void {
        $response = ClusterResponse::fromGatewayData(cluster_response_gateway_data(), cluster_request_id());

        expect($response->id)
            ->toBe(3)
            ->and($response->nodes)
            ->toHaveCount(2)
            ->each
            ->toBeInstanceOf(ClusterNodeResponse::class)
            ->and($response->router)
            ->toBeInstanceOf(ClusterNodeResponse::class)
            ->and($response->toArray())
            ->toBe([
                ...cluster_response_gateway_data(),
                'request_id' => cluster_request_id(),
            ]);
    });

    it('drops malformed nested Nodes and bounds malformed optional values', function (): void {
        $response = ClusterResponse::fromGatewayData([
            'id' => '3',
            'tld' => 42,
            'nodes' => ['invalid', ['id' => 2, 'name' => 'app-dev']],
            'router' => 'invalid',
        ], cluster_request_id());

        expect($response->id)
            ->toBe(0)
            ->and($response->tld)
            ->toBeNull()
            ->and($response->nodes)
            ->toHaveCount(1)
            ->and($response->router)
            ->toBeNull();
    });
});

/** @return array<string, mixed> */
function cluster_response_gateway_data(): array
{
    return [
        'id' => 3,
        'name' => 'development',
        'tld' => 'beast',
        'state' => 'active',
        'nodes' => [
            [
                'id' => 2,
                'name' => 'app-dev',
                'status' => 'active',
                'wireguard_ip' => '10.44.0.2',
                'lan_ip' => '10.0.0.2',
            ],
            [
                'id' => 3,
                'name' => 'app-prod',
                'status' => 'active',
                'wireguard_ip' => '10.44.0.3',
                'lan_ip' => null,
            ],
        ],
        'router' => [
            'id' => 2,
            'name' => 'app-dev',
            'status' => 'active',
            'wireguard_ip' => '10.44.0.2',
            'lan_ip' => '10.0.0.2',
        ],
    ];
}

function cluster_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
}
