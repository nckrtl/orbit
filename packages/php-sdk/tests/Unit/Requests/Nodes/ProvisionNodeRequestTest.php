<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Nodes\ProvisionNodeRequest;
use Orbit\Sdk\Responses\Nodes\NodeResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

it('sends node provisioning input and maps the typed response', function (): void {
    $mockClient = new MockClient([
        ProvisionNodeRequest::class => MockResponse::make([
            'data' => [
                'id' => 1,
                'name' => 'app-dev',
                'status' => 'active',
                'platform' => 'ubuntu',
                'architecture' => 'x86_64',
                'public_ssh_host' => '94.237.40.75',
                'public_ssh_port' => 22,
                'ssh_user' => 'orbit',
                'wireguard_address' => '10.44.0.2',
                'ssh_host_fingerprint' => 'SHA256:app-dev',
                'failed_step' => null,
                'error_code' => null,
                'roles' => ['app-dev'],
            ],
            'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
        ], 201),
    ]);
    $connector = new GatewayConnector('https://10.44.0.1');
    $connector->withMockClient($mockClient);
    $request = new ProvisionNodeRequest(
        name: 'app-dev',
        publicSshHost: '94.237.40.75',
        roles: ['app-dev'],
        wireguardAddress: '10.44.0.2',
        wireguardEndpointOverride: '10.0.0.2:51820',
        dnsServerOverride: '10.0.0.2',
    );

    $response = $connector->send($request)->dto();

    expect($request->getMethod())
        ->toBe(Method::POST)
        ->and($request->resolveEndpoint())
        ->toBe('/api/v1/nodes')
        ->and($request->body()->all())
        ->toBe([
            'name' => 'app-dev',
            'public_ssh_host' => '94.237.40.75',
            'public_ssh_port' => 22,
            'ssh_user' => 'root',
            'roles' => ['app-dev'],
            'wireguard_address' => '10.44.0.2',
            'wireguard_endpoint_override' => '10.0.0.2:51820',
            'dns_server_override' => '10.0.0.2',
        ])
        ->and($response)
        ->toBeInstanceOf(NodeResponse::class)
        ->and($response->name)
        ->toBe('app-dev')
        ->and($response->roles)
        ->toBe(['app-dev'])
        ->and($response->requestId)
        ->toBe('0198e15c-bf97-7c23-8f1f-61b8fe67a844');
});
