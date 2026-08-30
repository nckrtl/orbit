<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Nodes\UpdateNodeSettingsRequest;
use Orbit\Sdk\Responses\Nodes\NodeResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

it('sends a partial settings patch and preserves omitted members', function (): void {
    $mockClient = new MockClient([
        UpdateNodeSettingsRequest::class => MockResponse::make([
            'data' => [
                'id' => 2,
                'name' => 'app-dev',
                'status' => 'active',
                'public_ssh_host' => '94.237.40.75',
                'public_ssh_port' => 22,
                'user' => 'orbit',
                'roles' => ['app-dev'],
                'settings' => [
                    'instance' => ['path' => '/srv/orbit/instances'],
                    'worktree' => ['path' => '/srv/orbit/worktrees'],
                ],
            ],
            'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844'],
        ]),
    ]);
    $connector = new GatewayConnector('https://10.44.0.1');
    $connector->withMockClient($mockClient);
    $request = new UpdateNodeSettingsRequest(
        nodeId: 2,
        hasInstance: true,
        instancePath: '/srv/orbit/instances',
    );

    $response = $connector->send($request)->dto();

    expect($request->getMethod())
        ->toBe(Method::PATCH)
        ->and($request->resolveEndpoint())
        ->toBe('/api/v1/nodes/2/settings')
        ->and($request->body()->all())
        ->toBe([
            'instance' => ['path' => '/srv/orbit/instances'],
        ])
        ->and($response)
        ->toBeInstanceOf(NodeResponse::class)
        ->and($response->settings?->instancePath)
        ->toBe('/srv/orbit/instances')
        ->and($response->settings?->worktreePath)
        ->toBe('/srv/orbit/worktrees');
});

it('sends an explicit null nested member to unset a setting', function (): void {
    $request = new UpdateNodeSettingsRequest(
        nodeId: 2,
        hasWorktree: true,
        worktreePath: null,
    );

    expect($request->body()->all())->toBe([
        'worktree' => null,
    ]);
});
