<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Nodes\RenameNodeRequest;
use Orbit\Sdk\Responses\Nodes\NodeResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

it('sends a node name patch', function (): void {
    $mockClient = new MockClient([
        RenameNodeRequest::class => MockResponse::make([
            'data' => [
                'id' => 1,
                'name' => 'vpn',
                'status' => 'active',
                'public_ssh_host' => '94.237.46.255',
                'public_ssh_port' => 22,
                'user' => 'orbit',
                'roles' => ['gateway', 'vpn'],
            ],
            'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
        ]),
    ]);
    $connector = new GatewayConnector('https://10.44.0.1');
    $connector->withMockClient($mockClient);
    $request = new RenameNodeRequest(nodeId: 1, name: 'vpn');

    $response = $connector->send($request)->dto();

    expect($request->getMethod())
        ->toBe(Method::PATCH)
        ->and($request->resolveEndpoint())
        ->toBe('/api/v1/nodes/1/name')
        ->and($request->body()->all())
        ->toBe(['name' => 'vpn'])
        ->and($response)
        ->toBeInstanceOf(NodeResponse::class)
        ->and($response->name)
        ->toBe('vpn')
        ->and($response->id)
        ->toBe(1);
});
