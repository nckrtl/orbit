<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Nodes\ProvisionNodeRequest;
use Orbit\Sdk\Responses\Nodes\NodeResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

it('omits absent optional node payload fields and maps the typed response', function (): void {
    $mockClient = new MockClient([
        ProvisionNodeRequest::class => MockResponse::make([
            'data' => [
                'id' => 1,
                'name' => 'app-dev',
                'status' => 'active',
                'platform' => 'linux',
                'architecture' => 'x86_64',
                'tld' => 'app-dev.orbit',
                'public_ssh_host' => '94.237.40.75',
                'public_ssh_port' => 22,
                'ssh_user' => 'orbit',
                'wireguard_address' => '10.44.0.2',
                'wireguard_public_key' => 'app-dev-public-key',
                'wireguard_endpoint_override' => '10.0.0.2:51820',
                'dns_server_override' => '10.0.0.2',
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
            'platform' => 'linux',
            'public_ssh_port' => 22,
            'ssh_user' => 'root',
            'roles' => ['app-dev'],
        ])
        ->and($response)
        ->toBeInstanceOf(NodeResponse::class)
        ->and($response->requestId)
        ->toBe('0198e15c-bf97-7c23-8f1f-61b8fe67a844');
});

it('sends explicit optional node payload fields exactly as supplied and maps the typed response', function (): void {
    $mockClient = new MockClient([
        ProvisionNodeRequest::class => MockResponse::make([
            'data' => [
                'id' => 1,
                'name' => 'app-dev',
                'status' => 'active',
                'platform' => 'linux',
                'architecture' => 'x86_64',
                'tld' => 'app-dev.orbit',
                'public_ssh_host' => '94.237.40.75',
                'public_ssh_port' => 22,
                'ssh_user' => 'orbit',
                'wireguard_address' => '10.44.0.2',
                'wireguard_public_key' => 'app-dev-public-key',
                'wireguard_endpoint_override' => '10.0.0.2:51820',
                'dns_server_override' => '10.0.0.2',
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
        hostKeyFingerprint: 'SHA256:5jCWsPXzMnd5zy5xVxZ2gzyjH9N3wVfL6n5X0M8W3uQ',
        platform: 'linux',
        architecture: 'x86_64',
        tld: '.App-Dev.Orbit',
        wireguardPublicKey: 'darwin-public-key',
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
            'platform' => 'linux',
            'architecture' => 'x86_64',
            'tld' => '.App-Dev.Orbit',
            'public_ssh_port' => 22,
            'ssh_user' => 'root',
            'roles' => ['app-dev'],
            'wireguard_address' => '10.44.0.2',
            'wireguard_public_key' => 'darwin-public-key',
            'wireguard_endpoint_override' => '10.0.0.2:51820',
            'dns_server_override' => '10.0.0.2',
            'host_key_fingerprint' => 'SHA256:5jCWsPXzMnd5zy5xVxZ2gzyjH9N3wVfL6n5X0M8W3uQ',
        ])
        ->and($response)
        ->toBeInstanceOf(NodeResponse::class)
        ->and($response->name)
        ->toBe('app-dev')
        ->and($response->platform)
        ->toBe('linux')
        ->and($response->roles)
        ->toBe(['app-dev'])
        ->and($response->tld)
        ->toBe('app-dev.orbit')
        ->and($response->wireguardPublicKey)
        ->toBe('app-dev-public-key')
        ->and($response->wireguardEndpointOverride)
        ->toBe('10.0.0.2:51820')
        ->and($response->dnsServerOverride)
        ->toBe('10.0.0.2')
        ->and($response->requestId)
        ->toBe('0198e15c-bf97-7c23-8f1f-61b8fe67a844');
});

it('represents a Darwin node without inventing a public SSH host', function (): void {
    $request = new ProvisionNodeRequest(
        name: 'macbook',
        publicSshHost: null,
        platform: 'darwin',
        architecture: 'arm64',
        tld: 'dev',
        wireguardPublicKey: 'mini-public-key',
    );

    expect($request->body()->all())->toBe([
        'name' => 'macbook',
        'platform' => 'darwin',
        'architecture' => 'arm64',
        'tld' => 'dev',
        'public_ssh_port' => 22,
        'ssh_user' => 'root',
        'roles' => [],
        'wireguard_public_key' => 'mini-public-key',
    ]);
});
