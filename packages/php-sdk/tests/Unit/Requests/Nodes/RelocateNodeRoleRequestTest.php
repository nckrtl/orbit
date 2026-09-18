<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Nodes\RelocateNodeRoleRequest;
use Orbit\Sdk\Responses\Nodes\NodeRoleMutationResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

describe(RelocateNodeRoleRequest::class, function (): void {
    it('uses the numeric node ID exact body and typed mutation response', function (): void {
        $mockClient = new MockClient([
            RelocateNodeRoleRequest::class => MockResponse::make([
                'data' => [
                    ...node_role_added_gateway_data(),
                    'node_name' => 'beast',
                    'role' => 'gateway',
                    'assignment' => [
                        'id' => 2,
                        'role' => 'gateway',
                        'status' => 'active',
                        'failed_step' => null,
                        'error_code' => null,
                    ],
                ],
                'meta' => ['request_id' => node_role_request_id()],
            ]),
        ]);
        $connector = node_role_gateway_connector($mockClient);
        $request = new RelocateNodeRoleRequest(nodeId: 7, role: 'gateway', force: true);

        $response = $connector->send($request)->dto();
        $pendingRequest = $mockClient->getLastPendingRequest();

        expect($request->getMethod())
            ->toBe(Method::POST)
            ->and($request->resolveEndpoint())
            ->toBe('/api/v1/nodes/7/roles/gateway/relocate')
            ->and($pendingRequest?->headers()->get('X-Orbit-Request-Id'))
            ->toBe('11111111-1111-4111-8111-111111111111')
            ->and($pendingRequest?->body()->all())
            ->toBe(['force' => true])
            ->and($response)
            ->toBeInstanceOf(NodeRoleMutationResponse::class)
            ->and($response->requestId)
            ->toBe(node_role_request_id())
            ->and($response->toArray())
            ->toBe([
                'node_id' => 7,
                'node_name' => 'beast',
                'role' => 'gateway',
                'assignment' => [
                    'id' => 2,
                    'role' => 'gateway',
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
            RelocateNodeRoleRequest::class => MockResponse::make(
                [
                    'error' => [
                        'code' => 'validation.failed',
                        'message' => 'Use --force to relocate this node role.',
                        'details' => [
                            'field' => 'force',
                            'reason' => 'destructive_consent_required',
                            'role' => 'gateway',
                            'dependents' => [],
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
            $connector->send(new RelocateNodeRoleRequest(nodeId: 7, role: 'gateway', force: false))->dto();
            test()->fail('Expected GatewayApiException.');
        } catch (GatewayApiException $exception) {
            expect($exception->getMessage())
                ->toBe('Use --force to relocate this node role.')
                ->and($exception->errorCode())
                ->toBe('validation.failed')
                ->and($exception->details())
                ->toBe([
                    'field' => 'force',
                    'reason' => 'destructive_consent_required',
                    'role' => 'gateway',
                    'dependents' => [],
                ])
                ->and($exception->requestId())
                ->toBe('0198e15d-16c4-7855-8eb2-182b53ad28ba');
        }
    });
});
