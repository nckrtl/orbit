<?php

declare(strict_types=1);

use Orbit\Sdk\Responses\AppInstances\AppInstanceResponse;

describe(AppInstanceResponse::class, function (): void {
    it('maps every public AppInstance field from gateway data', function (): void {
        $response = AppInstanceResponse::fromGatewayData([
            'id' => 7,
            'app_id' => 3,
            'node_id' => 4,
            'name' => 'main',
            'environment' => 'development',
            'source_kind' => 'managed_clone',
            'checkout_path' => '/home/orbit/apps/orbit-docs',
            'root' => null,
            'effective_root' => 'public',
            'selected_branch' => 'main',
            'starting_commit' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'status' => 'active',
        ], '0198e15c-bf97-7c23-8f1f-61b8fe67a844');

        expect($response->toArray())->toBe([
            'id' => 7,
            'app_id' => 3,
            'node_id' => 4,
            'name' => 'main',
            'environment' => 'development',
            'source_kind' => 'managed_clone',
            'checkout_path' => '/home/orbit/apps/orbit-docs',
            'root' => null,
            'effective_root' => 'public',
            'selected_branch' => 'main',
            'starting_commit' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'status' => 'active',
            'route' => null,
            'hostname' => null,
            'url' => null,
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
});
