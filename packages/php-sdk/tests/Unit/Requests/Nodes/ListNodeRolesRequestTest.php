<?php

declare(strict_types=1);

use Orbit\Sdk\Requests\Nodes\ListNodeRolesRequest;
use Orbit\Sdk\Responses\Nodes\NodeRoleAssignmentResponse;
use Orbit\Sdk\Responses\Nodes\NodeRolesResponse;
use Saloon\Enums\Method;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

describe(ListNodeRolesRequest::class, function (): void {
    it('uses the numeric node ID and maps the list response with the request id', function (): void {
        $mockClient = new MockClient([
            ListNodeRolesRequest::class => MockResponse::make([
                'data' => [
                    [
                        'id' => 12,
                        'role' => 'app-dev',
                        'status' => 'active',
                        'failed_step' => null,
                        'error_code' => null,
                    ],
                    [
                        'id' => 13,
                        'role' => 'app-prod',
                        'status' => 'failed',
                        'failed_step' => 'converge:packages',
                        'error_code' => 'packages.failed',
                    ],
                ],
                'meta' => ['request_id' => node_role_request_id()],
            ]),
        ]);
        $connector = node_role_gateway_connector($mockClient);

        $response = $connector->send(new ListNodeRolesRequest(7))->dto();
        $request = $mockClient->getLastRequest();

        expect($request?->getMethod())
            ->toBe(Method::GET)
            ->and($request?->resolveEndpoint())
            ->toBe('/api/v1/nodes/7/roles')
            ->and($mockClient->getLastPendingRequest()?->headers()->get('X-Orbit-Request-Id'))
            ->toBe('11111111-1111-4111-8111-111111111111')
            ->and($response)
            ->toBeInstanceOf(NodeRolesResponse::class)
            ->and($response->assignments)
            ->toHaveCount(2)
            ->and($response->assignments[0])
            ->toBeInstanceOf(NodeRoleAssignmentResponse::class)
            ->and($response->requestId)
            ->toBe(node_role_request_id())
            ->and($response->toArray())
            ->toBe([
                'assignments' => [
                    [
                        'id' => 12,
                        'role' => 'app-dev',
                        'status' => 'active',
                        'failed_step' => null,
                        'error_code' => null,
                    ],
                    [
                        'id' => 13,
                        'role' => 'app-prod',
                        'status' => 'failed',
                        'failed_step' => 'converge:packages',
                        'error_code' => 'packages.failed',
                    ],
                ],
                'request_id' => node_role_request_id(),
            ]);
    });
});
