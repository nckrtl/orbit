<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Responses\Nodes\NodeRoleAssignmentResponse;
use Orbit\Sdk\Responses\Nodes\NodeRoleMutationResponse;
use Orbit\Sdk\Responses\Nodes\NodeRolesResponse;
use Saloon\Http\Faking\MockClient;

describe('node role assignment response transport', function (): void {
    it('maps list and mutation responses with the stable request id', function (): void {
        $list = new NodeRolesResponse(
            assignments: [
                NodeRoleAssignmentResponse::fromGatewayData([
                    'id' => 34,
                    'role' => 'app-dev',
                    'status' => 'provisioning',
                    'failed_step' => null,
                    'error_code' => null,
                ], node_role_request_id()),
            ],
            requestId: node_role_request_id(),
        );
        $mutation = NodeRoleMutationResponse::fromGatewayData(
            node_role_added_gateway_data(),
            node_role_request_id(),
        );

        expect($list->toArray())
            ->toBe([
                'assignments' => [[
                    'id' => 34,
                    'role' => 'app-dev',
                    'status' => 'provisioning',
                    'failed_step' => null,
                    'error_code' => null,
                ]],
                'request_id' => node_role_request_id(),
            ])
            ->and($mutation->toArray())
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
                'request_id' => node_role_request_id(),
            ]);
    });

    it('accepts provisioning and removing assignment statuses', function (): void {
        $provisioning = NodeRoleAssignmentResponse::fromGatewayData([
            'id' => 34,
            'role' => 'app-dev',
            'status' => 'provisioning',
            'failed_step' => null,
            'error_code' => null,
        ], node_role_request_id());
        $removing = NodeRoleAssignmentResponse::fromGatewayData([
            'id' => 35,
            'role' => 'app-prod',
            'status' => 'removing',
            'failed_step' => 'remove:firewall',
            'error_code' => 'node_role.remove_failed',
        ], node_role_request_id());

        expect($provisioning->status)
            ->toBe('provisioning')
            ->and($removing->status)
            ->toBe('removing')
            ->and($removing->failedStep)
            ->toBe('remove:firewall');
    });

    it('rejects malformed ids unsafe roles unsupported statuses and invalid failure fields', function (): void {
        assert_node_role_boundary_exception(
            fn (): NodeRoleAssignmentResponse => NodeRoleAssignmentResponse::fromGatewayData([
                'id' => '34',
                'role' => 'app-dev',
                'status' => 'active',
            ], node_role_request_id()),
            message: 'Gateway response contains an invalid node role assignment id.',
        );
        assert_node_role_boundary_exception(
            fn (): NodeRoleAssignmentResponse => NodeRoleAssignmentResponse::fromGatewayData([
                'id' => 34,
                'role' => '',
                'status' => 'active',
            ], node_role_request_id()),
            message: 'Gateway response contains an invalid node role assignment role.',
        );
        assert_node_role_boundary_exception(
            fn (): NodeRoleAssignmentResponse => NodeRoleAssignmentResponse::fromGatewayData([
                'id' => 34,
                'role' => 'token=secret',
                'status' => 'active',
            ], node_role_request_id()),
            message: 'Gateway response contains an invalid node role assignment role.',
        );
        assert_node_role_boundary_exception(
            fn (): NodeRoleAssignmentResponse => NodeRoleAssignmentResponse::fromGatewayData([
                'id' => 34,
                'role' => 'future-role',
                'status' => 'draining',
            ], node_role_request_id()),
            message: 'Gateway response contains an invalid node role assignment status.',
        );
        assert_node_role_boundary_exception(
            fn (): NodeRoleAssignmentResponse => NodeRoleAssignmentResponse::fromGatewayData([
                'id' => 34,
                'role' => 'app-dev',
                'status' => 'failed',
                'failed_step' => ['bad'],
                'error_code' => null,
            ], node_role_request_id()),
            message: 'Gateway response contains an invalid node role assignment failed_step.',
        );
        assert_node_role_boundary_exception(
            fn (): NodeRoleAssignmentResponse => NodeRoleAssignmentResponse::fromGatewayData([
                'id' => 34,
                'role' => 'app-dev',
                'status' => 'failed',
                'failed_step' => 'remove:baseline',
                'error_code' => ['bad'],
            ], node_role_request_id()),
            message: 'Gateway response contains an invalid node role assignment error_code.',
        );
        assert_node_role_boundary_exception(
            fn (): NodeRoleAssignmentResponse => NodeRoleAssignmentResponse::fromGatewayData([
                'id' => 34,
                'role' => 'app-dev',
                'status' => 'failed',
                'failed_step' => "converge:caddy config\r\nx-token: ".node_role_secret('failed-step-control'),
                'error_code' => null,
            ], node_role_request_id()),
            message: 'Gateway response contains an invalid node role assignment failed_step.',
            rejected: ["converge:caddy config\r\nx-token: ".node_role_secret('failed-step-control')],
        );
        assert_node_role_boundary_exception(
            fn (): NodeRoleAssignmentResponse => NodeRoleAssignmentResponse::fromGatewayData([
                'id' => 34,
                'role' => 'app-dev',
                'status' => 'failed',
                'failed_step' => str_repeat('a', times: 129),
                'error_code' => null,
            ], node_role_request_id()),
            message: 'Gateway response contains an invalid node role assignment failed_step.',
            rejected: [str_repeat('a', times: 129)],
        );
    });
});

describe('node role mutation response transport', function (): void {
    it('enforces the add and removal mutation invariant', function (): void {
        assert_node_role_boundary_exception(
            fn (): NodeRoleMutationResponse => NodeRoleMutationResponse::fromGatewayData([
                'node_id' => 7,
                'node_name' => 'app-1',
                'role' => 'app-dev',
                'assignment' => null,
                'removed' => false,
            ], node_role_request_id()),
            message: 'Gateway response contains an invalid node role mutation state.',
        );
        assert_node_role_boundary_exception(
            fn (): NodeRoleMutationResponse => NodeRoleMutationResponse::fromGatewayData([
                'node_id' => 7,
                'node_name' => 'app-1',
                'role' => 'app-dev',
                'assignment' => [
                    'id' => 34,
                    'role' => 'app-dev',
                    'status' => 'removing',
                    'failed_step' => 'remove:firewall',
                    'error_code' => null,
                ],
                'removed' => true,
            ], node_role_request_id()),
            message: 'Gateway response contains an invalid node role mutation state.',
        );
    });

    it('rejects malformed node ids for node role mutations', function (): void {
        assert_node_role_boundary_exception(
            fn (): NodeRoleMutationResponse => NodeRoleMutationResponse::fromGatewayData([
                'node_id' => '7',
                'node_name' => 'app-1',
                'role' => 'app-dev',
                'assignment' => null,
                'removed' => true,
            ], node_role_request_id()),
            message: 'Gateway response contains an invalid node role mutation node_id.',
        );
        assert_node_role_boundary_exception(
            fn (): NodeRoleMutationResponse => NodeRoleMutationResponse::fromGatewayData([
                'node_id' => 0,
                'node_name' => 'app-1',
                'role' => 'app-dev',
                'assignment' => null,
                'removed' => true,
            ], node_role_request_id()),
            message: 'Gateway response contains an invalid node role mutation node_id.',
        );
        assert_node_role_boundary_exception(
            fn (): NodeRoleMutationResponse => NodeRoleMutationResponse::fromGatewayData([
                'node_id' => 7,
                'node_name' => 'app-1',
                'role' => 'app-dev',
                'assignment' => null,
                'removed' => 'true',
            ], node_role_request_id()),
            message: 'Gateway response contains an invalid node role mutation removed flag.',
        );
        assert_node_role_boundary_exception(
            fn (): NodeRoleMutationResponse => NodeRoleMutationResponse::fromGatewayData([
                'node_id' => 7,
                'node_name' => 'app-1',
                'role' => 'app-dev',
                'assignment' => null,
                'removed' => 1,
            ], node_role_request_id()),
            message: 'Gateway response contains an invalid node role mutation removed flag.',
        );
        assert_node_role_boundary_exception(
            fn (): NodeRoleMutationResponse => NodeRoleMutationResponse::fromGatewayData([
                'node_id' => 7,
                'node_name' => 'app-1',
                'role' => 'app-dev',
                'assignment' => null,
            ], node_role_request_id()),
            message: 'Gateway response contains an invalid node role mutation removed flag.',
        );
    });

    it('rejects unsafe or empty node names for node role mutations', function (): void {
        assert_node_role_boundary_exception(
            fn (): NodeRoleMutationResponse => NodeRoleMutationResponse::fromGatewayData([
                'node_id' => 7,
                'node_name' => '',
                'role' => 'app-dev',
                'assignment' => null,
                'removed' => true,
            ], node_role_request_id()),
            message: 'Gateway response contains an invalid node role mutation node_name.',
        );
        assert_node_role_boundary_exception(
            fn (): NodeRoleMutationResponse => NodeRoleMutationResponse::fromGatewayData([
                'node_id' => 7,
                'node_name' => ['bad'],
                'role' => 'app-dev',
                'assignment' => null,
                'removed' => true,
            ], node_role_request_id()),
            message: 'Gateway response contains an invalid node role mutation node_name.',
        );
        assert_node_role_boundary_exception(
            fn (): NodeRoleMutationResponse => NodeRoleMutationResponse::fromGatewayData([
                'node_id' => 7,
                'node_name' => 'app.prod',
                'role' => 'app-dev',
                'assignment' => null,
                'removed' => true,
            ], node_role_request_id()),
            message: 'Gateway response contains an invalid node role mutation node_name.',
        );
        assert_node_role_boundary_exception(
            fn (): NodeRoleMutationResponse => NodeRoleMutationResponse::fromGatewayData([
                'node_id' => 7,
                'node_name' => "app-1\r\nx-token: ".node_role_secret('node-name-control'),
                'role' => 'app-dev',
                'assignment' => null,
                'removed' => true,
            ], node_role_request_id()),
            message: 'Gateway response contains an invalid node role mutation node_name.',
            rejected: ["app-1\r\nx-token: ".node_role_secret('node-name-control')],
        );
        assert_node_role_boundary_exception(
            fn (): NodeRoleMutationResponse => NodeRoleMutationResponse::fromGatewayData([
                'node_id' => 7,
                'node_name' => str_repeat('a', times: 64),
                'role' => 'app-dev',
                'assignment' => null,
                'removed' => true,
            ], node_role_request_id()),
            message: 'Gateway response contains an invalid node role mutation node_name.',
            rejected: [str_repeat('a', times: 64)],
        );
    });

    it('rejects unsafe roles for node role mutations', function (): void {
        assert_node_role_boundary_exception(
            fn (): NodeRoleMutationResponse => NodeRoleMutationResponse::fromGatewayData([
                'node_id' => 7,
                'node_name' => 'app-1',
                'role' => '',
                'assignment' => null,
                'removed' => true,
            ], node_role_request_id()),
            message: 'Gateway response contains an invalid node role mutation role.',
        );
        assert_node_role_boundary_exception(
            fn (): NodeRoleMutationResponse => NodeRoleMutationResponse::fromGatewayData([
                'node_id' => 7,
                'node_name' => 'app-1',
                'role' => "app-dev\r\nx-token: blocked",
                'assignment' => null,
                'removed' => true,
            ], node_role_request_id()),
            message: 'Gateway response contains an invalid node role mutation role.',
            rejected: ["app-dev\r\nx-token: blocked"],
        );
    });

    it('rejects non-array assignment values for node role mutations', function (): void {
        assert_node_role_boundary_exception(
            fn (): NodeRoleMutationResponse => NodeRoleMutationResponse::fromGatewayData([
                'node_id' => 7,
                'node_name' => 'app-1',
                'role' => 'app-dev',
                'assignment' => 'invalid',
                'removed' => false,
            ], node_role_request_id()),
            message: 'Gateway response contains an invalid node role mutation assignment.',
        );
        assert_node_role_boundary_exception(
            fn (): NodeRoleMutationResponse => NodeRoleMutationResponse::fromGatewayData([
                'node_id' => 7,
                'node_name' => 'app-1',
                'role' => 'app-dev',
                'assignment' => 123,
                'removed' => false,
            ], node_role_request_id()),
            message: 'Gateway response contains an invalid node role mutation assignment.',
        );
    });
});

function node_role_gateway_connector(MockClient $mockClient): GatewayConnector
{
    $connector = new GatewayConnector(
        'https://10.44.0.1',
        requestIdResolver: static fn (): string => '11111111-1111-4111-8111-111111111111',
    );
    $connector->withMockClient($mockClient);

    return $connector;
}

/** @return array<string, mixed> */
function node_role_added_gateway_data(): array
{
    return [
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
    ];
}

/** @return array<string, mixed> */
function node_role_removed_gateway_data(): array
{
    return [
        'node_id' => 7,
        'node_name' => 'app-1',
        'role' => 'app-dev',
        'assignment' => null,
        'removed' => true,
    ];
}

function node_role_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
}

function node_role_secret(string $label): string
{
    return "{$label}-".substr(hash('sha256', $label), offset: 0, length: 12);
}

/** @param list<string> $rejected */
function assert_node_role_boundary_exception(callable $callback, string $message, array $rejected = []): void
{
    try {
        $callback();
        test()->fail('Expected GatewayApiException.');
    } catch (GatewayApiException $exception) {
        $diagnostics = implode("\n", [
            $exception->getMessage(),
            (string) $exception,
            print_r($exception, return: true),
            (string) json_encode($exception->__debugInfo()),
        ]);

        expect($exception->getMessage())
            ->toBe($message)
            ->and($exception->requestId())
            ->toBe(node_role_request_id());

        foreach ($rejected as $value) {
            expect($diagnostics)->not->toContain($value);
        }
    }
}
