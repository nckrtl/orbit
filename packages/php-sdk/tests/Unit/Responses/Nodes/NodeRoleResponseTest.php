<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\Responses\Nodes\NodeRoleAssignmentResponse;
use Orbit\Sdk\Responses\Nodes\NodeRoleResponse;

describe(NodeRoleResponse::class, function (): void {
    it('maps the exact typed lifecycle response', function (): void {
        expect(class_exists(NodeRoleResponse::class))->toBeTrue();

        $response = NodeRoleResponse::fromGatewayData(
            node_role_response_gateway_data(),
            node_role_response_request_id(),
        );

        expect($response->nodeId)
            ->toBe(12)
            ->and($response->nodeName)
            ->toBe('mini')
            ->and($response->assignment)
            ->toBeInstanceOf(NodeRoleAssignmentResponse::class)
            ->and($response->assignment->role)
            ->toBe('app-dev')
            ->and($response->assignment->status)
            ->toBe('provisioning')
            ->and($response->assignment->localActionRequired)
            ->toBeTrue()
            ->and($response->assignment->localCommand)
            ->toBe('orbit node:setup app-dev')
            ->and($response->toArray())
            ->toBe([
                ...node_role_response_gateway_data(),
                'request_id' => node_role_response_request_id(),
            ]);
    });

    it('accepts only lifecycle statuses', function (string $status): void {
        $data = node_role_assignment_gateway_data();
        $data['status'] = $status;

        expect(NodeRoleAssignmentResponse::fromGatewayData($data)->status)->toBe($status);
    })->with([
        'provisioning' => ['provisioning'],
        'active' => ['active'],
        'failed' => ['failed'],
    ]);

    it('fails arbitrary role-assignment commands closed to null', function (): void {
        $data = node_role_assignment_gateway_data();
        $data['local_command'] = 'sudo arbitrary-command';

        $response = NodeRoleAssignmentResponse::fromGatewayData($data);

        expect($response->localCommand)
            ->toBeNull()
            ->and($response->toArray()['local_command'])
            ->toBeNull();
    });

    it('drops unsafe assignment error codes', function (): void {
        $credential = 'node-role-error-code-credential';
        $data = node_role_assignment_gateway_data();
        $data['error_code'] = "token={$credential}\r\nX-Orbit-Control: {$credential}";

        $response = NodeRoleAssignmentResponse::fromGatewayData($data);
        $diagnostics = print_r($response, return: true).serialize($response).json_encode($response->toArray());

        expect($response->errorCode)
            ->toBeNull()
            ->and($diagnostics)
            ->not->toContain($credential);
    });

    it('rejects malformed role assignments', function (string $key, mixed $value): void {
        $data = node_role_assignment_gateway_data();
        $data[$key] = $value;

        expect(fn (): NodeRoleAssignmentResponse => NodeRoleAssignmentResponse::fromGatewayData($data))
            ->toThrow(GatewayApiException::class, 'Gateway response contains an invalid node role assignment.');
    })->with([
        'missing role' => ['role', null],
        'empty role' => ['role', ''],
        'control-bearing role' => ['role', "app-dev\n"],
        'unknown status' => ['status', 'unknown'],
        'missing local action flag' => ['local_action_required', null],
        'non-string failed step' => ['failed_step', ['local-setup']],
        'non-string error code' => ['error_code', ['macos.setup_failed']],
        'non-string local command' => ['local_command', ['orbit node:setup app-dev']],
    ]);

    it('rejects malformed node role envelopes', function (array $data): void {
        expect(fn (): NodeRoleResponse => NodeRoleResponse::fromGatewayData(
            $data,
            node_role_response_request_id(),
        ))
            ->toThrow(GatewayApiException::class, 'Gateway response contains an invalid node role result.');
    })->with([
        'missing node ID' => [[
            'node_name' => 'mini',
            'assignment' => node_role_assignment_gateway_data(),
        ]],
        'non-positive node ID' => [[
            'node_id' => 0,
            'node_name' => 'mini',
            'assignment' => node_role_assignment_gateway_data(),
        ]],
        'missing node name' => [[
            'node_id' => 12,
            'assignment' => node_role_assignment_gateway_data(),
        ]],
        'wrong successful role' => [[
            'node_id' => 12,
            'node_name' => 'mini',
            'assignment' => [
                ...node_role_assignment_gateway_data(),
                'role' => 'app-prod',
            ],
        ]],
        'missing assignment' => [[
            'node_id' => 12,
            'node_name' => 'mini',
        ]],
    ]);
});

/** @return array<string, mixed> */
function node_role_response_gateway_data(): array
{
    return [
        'node_id' => 12,
        'node_name' => 'mini',
        'assignment' => node_role_assignment_gateway_data(),
    ];
}

/** @return array<string, mixed> */
function node_role_assignment_gateway_data(): array
{
    return [
        'role' => 'app-dev',
        'status' => 'provisioning',
        'failed_step' => null,
        'error_code' => null,
        'local_action_required' => true,
        'local_command' => 'orbit node:setup app-dev',
    ];
}

function node_role_response_request_id(): string
{
    return '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
}
