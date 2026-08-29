<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Nodes\ListNodesRequest;
use Orbit\Sdk\Responses\Nodes\NodeResponse;
use Orbit\Sdk\Responses\Nodes\NodesResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

it('maps the node collection and response metadata', function (): void {
    $mockClient = new MockClient([
        ListNodesRequest::class => MockResponse::make([
            'data' => list_nodes_gateway_data(),
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
        ->toBe(first_list_node_with_request_id())
        ->and($response->nodes[1]->failedStep)
        ->toBe('wireguard-server-validate')
        ->and($response->nodes[1]->errorCode)
        ->toBe('vpn.server_config_invalid')
        ->and($response->toArray())
        ->toBe([
            'nodes' => list_nodes_public_data(),
            'request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
        ]);
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

/** @return list<array<string, int|string|list<string>|null>> */
function list_nodes_gateway_data(): array
{
    return [
        [
            'id' => 4,
            'name' => 'app-dev',
            'status' => 'active',
            'platform' => 'ubuntu',
            'architecture' => 'x86_64',
            'tld' => 'app-dev.orbit',
            'public_ssh_host' => '94.237.40.75',
            'public_ssh_port' => 22,
            'user' => 'orbit',
            'wireguard_address' => '10.44.0.3',
            'wireguard_public_key' => 'app-dev-public-key',
            'wireguard_endpoint_override' => '10.0.0.2:51820',
            'dns_server_override' => '10.0.0.2',
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
            'tld' => null,
            'public_ssh_host' => '85.9.211.193',
            'public_ssh_port' => 2202,
            'user' => 'root',
            'wireguard_address' => null,
            'wireguard_public_key' => null,
            'wireguard_endpoint_override' => null,
            'dns_server_override' => null,
            'ssh_host_fingerprint' => null,
            'failed_step' => 'wireguard-server-validate',
            'error_code' => 'vpn.server_config_invalid',
            'roles' => ['app-prod'],
        ],
    ];
}

/** @return array<string, int|string|list<string>|null> */
function first_list_node_with_request_id(): array
{
    return [
        ...list_nodes_public_data()[0],
        'request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
    ];
}

/** @return list<array<string, int|string|list<string>|null>> */
function list_nodes_public_data(): array
{
    return list_nodes_gateway_data();
}
