<?php

declare(strict_types=1);

use Orbit\Sdk\Responses\Workspaces\WorkspaceResponse;

describe(WorkspaceResponse::class, function (): void {
    it('maps every public workspace field from gateway data', function (): void {
        $response = WorkspaceResponse::fromGatewayData([
            'id' => 9,
            'instance_id' => 7,
            'node_id' => 4,
            'name' => 'feature-auth',
            'branch' => 'feature/auth',
            'checkout_path' => '/home/orbit/apps/orbit-docs/main/.worktrees/feature-auth',
            'php_version' => null,
            'effective_php_version' => '8.5',
            'hostname' => 'feature-auth.main.beast',
            'status' => 'active',
            'failed_step' => null,
            'error_code' => null,
        ], '0198e15c-bf97-7c23-8f1f-61b8fe67a844');

        expect($response->toArray())->toBe([
            'id' => 9,
            'instance_id' => 7,
            'node_id' => 4,
            'name' => 'feature-auth',
            'branch' => 'feature/auth',
            'checkout_path' => '/home/orbit/apps/orbit-docs/main/.worktrees/feature-auth',
            'php_version' => null,
            'effective_php_version' => '8.5',
            'hostname' => 'feature-auth.main.beast',
            'status' => 'active',
            'failed_step' => null,
            'error_code' => null,
            'request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
        ]);
    });

    it('normalizes invalid nullable gateway fields', function (): void {
        $response = WorkspaceResponse::fromGatewayData([
            'php_version' => ['invalid'],
            'error_code' => false,
        ], 'request-id');

        expect($response->phpVersion)
            ->toBeNull()
            ->and($response->errorCode)
            ->toBeNull();
    });
});
