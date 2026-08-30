<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Nodes\AddNodeRoleRequest;
use Orbit\Sdk\Responses\Nodes\NodeRoleMutationResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

describe(AddNodeRoleRequest::class, function (): void {
    it('uses the numeric node ID exact body and typed mutation response', function (): void {
        $mockClient = new MockClient([
            AddNodeRoleRequest::class => MockResponse::make([
                'data' => node_role_added_gateway_data(),
                'meta' => ['request_id' => node_role_request_id()],
            ]),
        ]);
        $connector = node_role_gateway_connector($mockClient);
        $request = new AddNodeRoleRequest(nodeId: 7, role: 'app-dev', convergeExisting: true);

        $response = $connector->send($request)->dto();
        $pendingRequest = $mockClient->getLastPendingRequest();

        expect($request->getMethod())
            ->toBe(Method::POST)
            ->and($request->resolveEndpoint())
            ->toBe('/api/v1/nodes/7/roles')
            ->and($pendingRequest?->headers()->get('X-Orbit-Request-Id'))
            ->toBe('11111111-1111-4111-8111-111111111111')
            ->and($pendingRequest?->body()->all())
            ->toBe([
                'role' => 'app-dev',
                'converge_existing' => true,
            ])
            ->and($response)
            ->toBeInstanceOf(NodeRoleMutationResponse::class)
            ->and($response->requestId)
            ->toBe(node_role_request_id())
            ->and($response->toArray())
            ->toBe([
                'node_id' => 7,
                'node_name' => 'app-1',
                'role' => 'app-dev',
                'assignment' => [
                    'id' => 34,
                    'role' => 'app-dev',
                    'status' => 'active',
                    'failed_step' => null,
                    'error_code' => null,
                ],
                'removed' => false,
                'degradation' => null,
                'retained_on_node' => [],
                'follow_up' => null,
                'request_id' => node_role_request_id(),
            ]);
    });

    it('maps gateway validation failures with safe details and header request id', function (): void {
        $mockClient = new MockClient([
            AddNodeRoleRequest::class => MockResponse::make(
                [
                    'error' => [
                        'code' => 'validation.failed',
                        'message' => 'Role [gateway] is protected from generic mutation.',
                        'details' => [
                            'field' => 'role',
                            'role' => 'gateway',
                        ],
                    ],
                ],
                422,
                ['X-Orbit-Request-Id' => '0198e15d-16c4-7855-8eb2-182b53ad28ba'],
            ),
        ]);
        $connector = new GatewayConnector('https://10.44.0.1');
        $connector->withMockClient($mockClient);

        try {
            $connector->send(new AddNodeRoleRequest(nodeId: 7, role: 'gateway'))->dto();
            test()->fail('Expected GatewayApiException.');
        } catch (GatewayApiException $exception) {
            expect($exception->getMessage())
                ->toBe('Role [gateway] is protected from generic mutation.')
                ->and($exception->errorCode())
                ->toBe('validation.failed')
                ->and($exception->details())
                ->toBe([
                    'field' => 'role',
                    'role' => 'gateway',
                ])
                ->and($exception->requestId())
                ->toBe('0198e15d-16c4-7855-8eb2-182b53ad28ba');
        }
    });
});
