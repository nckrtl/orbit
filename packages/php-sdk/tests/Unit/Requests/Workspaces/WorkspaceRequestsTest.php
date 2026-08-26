<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Workspaces\CreateWorkspaceRequest;
use Orbit\Sdk\Requests\Workspaces\ListWorkspacesRequest;
use Orbit\Sdk\Requests\Workspaces\RemoveWorkspaceRequest;
use Orbit\Sdk\Requests\Workspaces\ShowWorkspaceRequest;
use Orbit\Sdk\Requests\Workspaces\UpdateWorkspacePhpRequest;
use Orbit\Sdk\Responses\Workspaces\WorkspaceResponse;
use Orbit\Sdk\Responses\Workspaces\WorkspacesResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

describe('workspace requests', function (): void {
    it('creates a workspace and omits fields that the Gateway defaults', function (): void {
        $mockClient = new MockClient([
            CreateWorkspaceRequest::class => MockResponse::make(workspace_envelope(), 201),
        ]);
        $connector = workspace_gateway_connector($mockClient);
        $request = new CreateWorkspaceRequest(instanceId: 7, name: 'feature-auth');

        $response = $connector->send($request)->dto();

        expect($request->getMethod())
            ->toBe(Method::POST)
            ->and($request->resolveEndpoint())
            ->toBe('/api/v1/workspaces')
            ->and($request->body()->all())
            ->toBe([
                'instance_id' => 7,
                'name' => 'feature-auth',
            ])
            ->and($response)
            ->toBeInstanceOf(WorkspaceResponse::class)
            ->and($response->requestId)
            ->toBe(workspace_request_id());
    });

    it('sends an explicit safe checkout path and PHP override', function (): void {
        $request = new CreateWorkspaceRequest(
            instanceId: 7,
            name: 'feature-auth',
            branch: 'feature/auth',
            checkoutPath: '/home/orbit/workspaces/orbit-docs-auth',
            phpVersion: '8.4',
        );

        expect($request->body()->all())->toBe([
            'instance_id' => 7,
            'name' => 'feature-auth',
            'branch' => 'feature/auth',
            'checkout_path' => '/home/orbit/workspaces/orbit-docs-auth',
            'php_version' => '8.4',
        ]);
    });

    it('lists workspaces through the explicit collection route', function (): void {
        $mockClient = new MockClient([
            ListWorkspacesRequest::class => MockResponse::make([
                'data' => [workspace_gateway_data()],
                'meta' => ['request_id' => workspace_request_id()],
            ]),
        ]);
        $connector = workspace_gateway_connector($mockClient);

        $response = $connector->send(new ListWorkspacesRequest)->dto();
        $request = $mockClient->getLastRequest();

        expect($request?->getMethod())
            ->toBe(Method::GET)
            ->and($request?->resolveEndpoint())
            ->toBe('/api/v1/workspaces')
            ->and($response)
            ->toBeInstanceOf(WorkspacesResponse::class)
            ->and($response->workspaces)
            ->toHaveCount(1)
            ->and($response->toArray())
            ->toBe([
                'workspaces' => [workspace_gateway_data()],
                'request_id' => workspace_request_id(),
            ]);
    });

    it('shows a workspace by numeric ID', function (): void {
        $mockClient = new MockClient([
            ShowWorkspaceRequest::class => MockResponse::make(workspace_envelope()),
        ]);
        $connector = workspace_gateway_connector($mockClient);

        $response = $connector->send(new ShowWorkspaceRequest(9))->dto();
        $request = $mockClient->getLastRequest();

        expect($request?->getMethod())
            ->toBe(Method::GET)
            ->and($request?->resolveEndpoint())
            ->toBe('/api/v1/workspaces/9')
            ->and($response)
            ->toBeInstanceOf(WorkspaceResponse::class);
    });

    it('removes a workspace and returns its deleted snapshot', function (): void {
        $mockClient = new MockClient([
            RemoveWorkspaceRequest::class => MockResponse::make(workspace_envelope()),
        ]);
        $connector = workspace_gateway_connector($mockClient);

        $response = $connector->send(new RemoveWorkspaceRequest(9))->dto();
        $request = $mockClient->getLastRequest();

        expect($request?->getMethod())
            ->toBe(Method::DELETE)
            ->and($request?->resolveEndpoint())
            ->toBe('/api/v1/workspaces/9')
            ->and($response->id)
            ->toBe(9);
    });

    it('changes the PHP version through its explicit action route', function (): void {
        $gatewayData = workspace_gateway_data();
        $gatewayData['php_version'] = '8.4';
        $mockClient = new MockClient([
            UpdateWorkspacePhpRequest::class => MockResponse::make([
                'data' => $gatewayData,
                'meta' => ['request_id' => workspace_request_id()],
            ]),
        ]);
        $connector = workspace_gateway_connector($mockClient);
        $request = new UpdateWorkspacePhpRequest(workspaceId: 9, phpVersion: '8.4');

        $response = $connector->send($request)->dto();

        expect($request->getMethod())
            ->toBe(Method::PATCH)
            ->and($request->resolveEndpoint())
            ->toBe('/api/v1/workspaces/9/php')
            ->and($request->body()->all())
            ->toBe(['php_version' => '8.4'])
            ->and($response)
            ->toBeInstanceOf(WorkspaceResponse::class)
            ->and($response->phpVersion)
            ->toBe('8.4');
    });
});

function workspace_gateway_connector(MockClient $mockClient): GatewayConnector
{
    $connector = new GatewayConnector('https://10.44.0.1');
    $connector->withMockClient($mockClient);

    return $connector;
}

/** @return array<string, mixed> */
function workspace_envelope(): array
{
    return [
        'data' => workspace_gateway_data(),
        'meta' => ['request_id' => workspace_request_id()],
    ];
}

/** @return array<string, mixed> */
function workspace_gateway_data(): array
{
    return [
        'id' => 9,
        'instance_id' => 7,
        'node_id' => 4,
        'name' => 'feature-auth',
        'branch' => 'feature/auth',
        'checkout_path' => '/home/orbit/.orbit/worktrees/orbit-docs/feature-auth',
        'php_version' => null,
        'effective_php_version' => '8.5',
        'hostname' => 'feature-auth.orbit-docs.beast',
        'status' => 'active',
        'failed_step' => null,
        'error_code' => null,
    ];
}

function workspace_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
}
