<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Nodes\AddNodeRoleRequest;
use Orbit\Sdk\Responses\Nodes\NodeRoleResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

describe(AddNodeRoleRequest::class, function (): void {
    it('posts one role to a numeric node and maps the lifecycle response', function (): void {
        expect(class_exists(AddNodeRoleRequest::class))->toBeTrue();

        $mockClient = new MockClient([
            AddNodeRoleRequest::class => MockResponse::make([
                'data' => add_node_role_gateway_data(),
                'meta' => ['request_id' => add_node_role_request_id()],
            ]),
        ]);
        $connector = new GatewayConnector(
            baseUrl: 'https://10.44.0.1',
            requestIdResolver: static fn (): string => add_node_role_request_id(),
        );
        $connector->withMockClient($mockClient);
        $request = new AddNodeRoleRequest(12, 'app-dev');

        $response = $connector->send($request)->dto();
        $headers = $mockClient->getLastPendingRequest()?->headers()->all() ?? [];

        expect($request->getMethod())
            ->toBe(Method::POST)
            ->and($request->resolveEndpoint())
            ->toBe('/api/v1/nodes/12/roles')
            ->and($request->body()->all())
            ->toBe(['role' => 'app-dev'])
            ->and($headers)
            ->toHaveKey('X-Orbit-Request-Id', add_node_role_request_id())
            ->and($response)
            ->toBeInstanceOf(NodeRoleResponse::class)
            ->and($response->toArray())
            ->toBe([
                ...add_node_role_gateway_data(),
                'request_id' => add_node_role_request_id(),
            ]);
    });

    it('preserves an explicit role string for Gateway policy', function (): void {
        $request = new AddNodeRoleRequest(12, 'unsupported-role');

        expect($request->body()->all())->toBe(['role' => 'unsupported-role']);
    });
});

/** @return array<string, mixed> */
function add_node_role_gateway_data(): array
{
    return [
        'node_id' => 12,
        'node_name' => 'mini',
        'assignment' => [
            'role' => 'app-dev',
            'status' => 'provisioning',
            'failed_step' => null,
            'error_code' => null,
            'local_action_required' => true,
            'local_command' => 'orbit node:setup app-dev',
        ],
    ];
}

function add_node_role_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
}
