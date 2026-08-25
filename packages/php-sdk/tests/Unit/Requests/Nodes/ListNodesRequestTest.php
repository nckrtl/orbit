<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Nodes\ListNodesRequest;
use Orbit\Sdk\Responses\Nodes\NodeResponse;
use Orbit\Sdk\Responses\Nodes\NodesResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

describe(ListNodesRequest::class, function (): void {
    it('maps the node collection and response metadata', function (): void {
        $mockClient = new MockClient([
            ListNodesRequest::class => MockResponse::make([
                'data' => [
                    [
                        'id' => 4,
                        'name' => 'app-dev',
                        'status' => 'active',
                        'platform' => 'ubuntu',
                        'architecture' => 'x86_64',
                        'public_ssh_host' => '94.237.40.75',
                        'public_ssh_port' => 22,
                        'ssh_user' => 'orbit',
                        'wireguard_address' => '10.44.0.3',
                        'ssh_host_fingerprint' => 'SHA256:app-dev',
                        'failed_step' => null,
                        'error_code' => null,
                        'roles' => ['app-dev'],
                    ],
                    [
                        'id' => 7,
                        'name' => 'app-prod',
                        'status' => 'failed',
                        'platform' => null,
                        'architecture' => null,
                        'public_ssh_host' => '85.9.211.193',
                        'public_ssh_port' => 2202,
                        'ssh_user' => 'root',
                        'wireguard_address' => null,
                        'ssh_host_fingerprint' => null,
                        'failed_step' => 'wireguard-server-validate',
                        'error_code' => 'vpn.server_config_invalid',
                        'roles' => ['app-prod'],
                    ],
                ],
                'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
            ]),
        ]);
        $connector = new GatewayConnector('https://10.44.0.1');
        $connector->withMockClient($mockClient);

        $response = $connector->send(new ListNodesRequest)->dto();
        $request = $mockClient->getLastRequest();

        expect($request?->getMethod())
            ->toBe(Method::GET)
            ->and($request?->resolveEndpoint())
            ->toBe('/api/v1/nodes')
            ->and($response)
            ->toBeInstanceOf(NodesResponse::class)
            ->and($response->requestId)
            ->toBe('0198e15c-bf97-7c23-8f1f-61b8fe67a844')
            ->and($response->nodes)
            ->toHaveCount(2)
            ->each
            ->toBeInstanceOf(NodeResponse::class)
            ->and($response->nodes[0]->toArray())
            ->toBe([
                'id' => 4,
                'name' => 'app-dev',
                'status' => 'active',
                'platform' => 'ubuntu',
                'architecture' => 'x86_64',
                'public_ssh_host' => '94.237.40.75',
                'public_ssh_port' => 22,
                'ssh_user' => 'orbit',
                'wireguard_address' => '10.44.0.3',
                'ssh_host_fingerprint' => 'SHA256:app-dev',
                'failed_step' => null,
                'error_code' => null,
                'roles' => ['app-dev'],
                'request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
            ])
            ->and($response->nodes[1]->failedStep)
            ->toBe('wireguard-server-validate')
            ->and($response->nodes[1]->errorCode)
            ->toBe('vpn.server_config_invalid');
    });

    it('maps an empty gateway collection', function (): void {
        $mockClient = new MockClient([
            ListNodesRequest::class => MockResponse::make([
                'data' => [],
                'meta' => ['request_id' => '0198e15d-16c4-7855-8eb2-182b53ad28ba'],
            ]),
        ]);
        $connector = new GatewayConnector('https://10.44.0.1');
        $connector->withMockClient($mockClient);

        $response = $connector->send(new ListNodesRequest)->dto();

        expect($response)
            ->toBeInstanceOf(NodesResponse::class)
            ->and($response->nodes)
            ->toBeEmpty()
            ->and($response->toArray())
            ->toBe([
                'nodes' => [],
                'request_id' => '0198e15d-16c4-7855-8eb2-182b53ad28ba',
            ]);
    });
});
