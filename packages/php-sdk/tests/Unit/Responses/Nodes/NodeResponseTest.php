<?php

declare(strict_types=1);

use Orbit\Sdk\Responses\Nodes\NodeAccessNodeResponse;
use Orbit\Sdk\Responses\Nodes\NodeAccessResponse;
use Orbit\Sdk\Responses\Nodes\NodeResponse;
use Orbit\Sdk\Responses\Nodes\NodesResponse;

it('preserves the original positional constructor contract', function (): void {
    $response = new NodeResponse(
        4,
        'app-dev',
        'active',
        '94.237.40.75',
        22,
        'orbit',
        '10.44.0.3',
        ['app-dev'],
        '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
    );

    expect($response->publicSshHost)
        ->toBe('94.237.40.75')
        ->and($response->publicSshPort)
        ->toBe(22)
        ->and($response->roles)
        ->toBe(['app-dev'])
        ->and($response->requestId)
        ->toBe('0198e15c-bf97-7c23-8f1f-61b8fe67a844')
        ->and($response->platform)
        ->toBeNull()
        ->and($response->architecture)
        ->toBeNull()
        ->and($response->tld)
        ->toBeNull()
        ->and($response->wireguardPublicKey)
        ->toBeNull()
        ->and($response->sshHostFingerprint)
        ->toBeNull()
        ->and($response->failedStep)
        ->toBeNull()
        ->and($response->access)
        ->toBeNull()
        ->and($response->errorCode)
        ->toBeNull();
});

it('preserves the original named constructor contract', function (): void {
    $response = new NodeResponse(
        id: 7,
        name: 'app-prod',
        status: 'active',
        publicSshHost: '85.9.211.193',
        publicSshPort: 22,
        sshUser: 'orbit',
        wireguardAddress: '10.44.0.4',
        roles: ['app-prod'],
        requestId: '0198e15d-16c4-7855-8eb2-182b53ad28ba',
    );

    expect($response->name)
        ->toBe('app-prod')
        ->and($response->platform)
        ->toBeNull()
        ->and($response->access)
        ->toBeNull()
        ->and($response->errorCode)
        ->toBeNull();
});

it('does not invent an SSH port for malformed gateway data', function (): void {
    $response = NodeResponse::fromGatewayData(
        ['public_ssh_port' => '22'],
        '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
    );

    expect($response->publicSshPort)->toBe(0);
});

it('omits access when the gateway does not send an access key', function (): void {
    $response = NodeResponse::fromGatewayData(node_response_gateway_data(), '0198e15c-bf97-7c23-8f1f-61b8fe67a844');

    expect($response->access)
        ->toBeNull()
        ->and($response->toArray())
        ->not->toHaveKey('access');
});

it('preserves valid access data in both directions', function (): void {
    $response = NodeResponse::fromGatewayData(node_response_gateway_data([
        'access' => [
            'can_access' => [
                ['id' => 3, 'name' => 'gateway'],
            ],
            'accessible_by' => [
                ['id' => 7, 'name' => 'maintainer'],
            ],
        ],
    ]), '0198e15c-bf97-7c23-8f1f-61b8fe67a844');

    expect($response->access)
        ->toBeInstanceOf(NodeAccessResponse::class)
        ->and($response->access?->canAccess[0])
        ->toBeInstanceOf(NodeAccessNodeResponse::class)
        ->and($response->access?->accessibleBy[0])
        ->toBeInstanceOf(NodeAccessNodeResponse::class)
        ->and($response->toArray()['access'] ?? null)
        ->toBe([
            'can_access' => [
                ['id' => 3, 'name' => 'gateway'],
            ],
            'accessible_by' => [
                ['id' => 7, 'name' => 'maintainer'],
            ],
        ]);
});

it('drops malformed access list entries instead of inventing synthetic nodes', function (): void {
    $response = NodeResponse::fromGatewayData(node_response_gateway_data([
        'access' => [
            'can_access' => [
                ['id' => 3, 'name' => 'gateway'],
                ['id' => 0, 'name' => 'bad-id'],
                ['id' => 5, 'name' => ''],
            ],
            'accessible_by' => [
                ['id' => '7', 'name' => 'bad-type'],
                ['id' => 8, 'name' => 'maintainer'],
            ],
        ],
    ]), '0198e15c-bf97-7c23-8f1f-61b8fe67a844');

    expect($response->access?->toArray())
        ->toBe([
            'can_access' => [
                ['id' => 3, 'name' => 'gateway'],
            ],
            'accessible_by' => [
                ['id' => 8, 'name' => 'maintainer'],
            ],
        ]);
});

it('keeps real access in node collections while stripping nested request ids', function (): void {
    $response = new NodesResponse([
        new NodeResponse(
            id: 4,
            name: 'app-dev',
            status: 'active',
            publicSshHost: '94.237.40.75',
            publicSshPort: 22,
            sshUser: 'orbit',
            wireguardAddress: '10.44.0.3',
            roles: ['app-dev'],
            requestId: '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
            access: new NodeAccessResponse(
                canAccess: [new NodeAccessNodeResponse(id: 3, name: 'gateway')],
                accessibleBy: [new NodeAccessNodeResponse(id: 7, name: 'maintainer')],
            ),
        ),
    ], '0198e15d-16c4-7855-8eb2-182b53ad28ba');

    expect($response->toArray())
        ->toBe([
            'nodes' => [[
                ...node_response_public_data(),
                'access' => [
                    'can_access' => [
                        ['id' => 3, 'name' => 'gateway'],
                    ],
                    'accessible_by' => [
                        ['id' => 7, 'name' => 'maintainer'],
                    ],
                ],
            ]],
            'request_id' => '0198e15d-16c4-7855-8eb2-182b53ad28ba',
        ]);
});

/** @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function node_response_gateway_data(array $overrides = []): array
{
    return array_replace([
        'id' => 4,
        'name' => 'app-dev',
        'status' => 'active',
        'public_ssh_host' => '94.237.40.75',
        'public_ssh_port' => 22,
        'ssh_user' => 'orbit',
        'wireguard_address' => '10.44.0.3',
        'roles' => ['app-dev'],
    ], $overrides);
}

/** @return array<string, int|string|list<string>|null> */
function node_response_public_data(): array
{
    return [
        'id' => 4,
        'name' => 'app-dev',
        'status' => 'active',
        'platform' => null,
        'architecture' => null,
        'tld' => null,
        'public_ssh_host' => '94.237.40.75',
        'public_ssh_port' => 22,
        'ssh_user' => 'orbit',
        'wireguard_address' => '10.44.0.3',
        'wireguard_public_key' => null,
        'wireguard_endpoint_override' => null,
        'dns_server_override' => null,
        'ssh_host_fingerprint' => null,
        'failed_step' => null,
        'error_code' => null,
        'roles' => ['app-dev'],
    ];
}
