<?php

declare(strict_types=1);

use Orbit\Sdk\Responses\AppInstances\AppInstanceRemovalProgressResponse;
use Orbit\Sdk\Responses\AppInstances\AppInstanceResponse;

describe(AppInstanceResponse::class, function (): void {
    it('maps every public AppInstance field from gateway data', function (): void {
        $response = AppInstanceResponse::fromGatewayData([
            'id' => 7,
            'app_id' => 3,
            'node_id' => 4,
            'vite_port' => null,
            'name' => 'main',
            'environment' => 'development',
            'source_layout' => 'checkout',
            'checkout_path' => '/home/orbit/apps/orbit-docs',
            'production_user' => null,
            'production_home' => null,
            'root' => null,
            'effective_root' => 'public',
            'selected_branch' => 'main',
            'branch_override' => 'main',
            'migration_required' => true,
            'starting_commit' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'detached' => false,
            'status' => 'active',
        ], '0198e15c-bf97-7c23-8f1f-61b8fe67a844');

        expect($response->toArray())->toBe([
            'id' => 7,
            'app_id' => 3,
            'node_id' => 4,
            'app' => null,
            'node' => null,
            'vite_port' => null,
            'name' => 'main',
            'environment' => 'development',
            'source_layout' => 'checkout',
            'checkout_path' => '/home/orbit/apps/orbit-docs',
            'production_user' => null,
            'production_home' => null,
            'root' => null,
            'effective_root' => 'public',
            'selected_branch' => 'main',
            'branch_override' => 'main',
            'migration_required' => true,
            'starting_commit' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'detached' => false,
            'status' => 'active',
            'route' => null,
            'domain' => null,
            'url' => null,
            'removal' => null,
            'transfer' => null,
            'deploy_steps' => [],
            'request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
        ]);
    });

    it('uses safe values for invalid gateway fields', function (): void {
        $response = AppInstanceResponse::fromGatewayData([
            'id' => 'invalid',
            'root' => ['invalid'],
        ], 'request-id');

        expect($response->id)
            ->toBe(0)
            ->and($response->root)
            ->toBeNull();
    });

    it('maps bounded removal progress and rejects an unsafe error code', function (): void {
        $response = AppInstanceRemovalProgressResponse::fromGatewayData([
            'operation_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a845',
            'id' => 7,
            'name' => 'main',
            'force' => false,
            'status' => 'failed',
            'current_step' => 'runtime_cleanup',
            'total' => 1,
            'completed' => 0,
            'remaining' => 1,
            'failed_step' => 'runtime_cleanup',
            'error_code' => "token=secret\r\nX-Control: injected",
        ]);

        expect($response->toArray())->toBe([
            'operation_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a845',
            'id' => 7,
            'name' => 'main',
            'force' => false,
            'status' => 'failed',
            'current_step' => 'runtime_cleanup',
            'total' => 1,
            'completed' => 0,
            'remaining' => 1,
            'failed_step' => 'runtime_cleanup',
            'error_code' => null,
        ]);
    });
});

it('exposes a valid assigned Vite port and rejects invalid transport values', function (): void {
    $response = AppInstanceResponse::fromGatewayData(['id' => 7, 'vite_port' => 5210], 'request-id');
    expect($response->vitePort)->toBe(5210)->and($response->toArray()['vite_port'])->toBe(5210);
    foreach ([0, 1023, 65536, '5173', false] as $invalid) {
        expect(AppInstanceResponse::fromGatewayData(['vite_port' => $invalid], 'request-id')->vitePort)->toBeNull();
    }
});
