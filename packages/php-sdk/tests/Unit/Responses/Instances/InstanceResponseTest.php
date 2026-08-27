<?php

declare(strict_types=1);

use Orbit\Sdk\Responses\Instances\InstanceResponse;

describe(InstanceResponse::class, function (): void {
    it('maps every public instance field from gateway data', function (): void {
        $response = InstanceResponse::fromGatewayData([
            'id' => 7,
            'app_id' => 3,
            'node_id' => 4,
            'name' => 'main',
            'environment' => 'development',
            'checkout_path' => '/home/orbit/apps/orbit-docs',
            'document_root' => 'public',
            'php_version' => '8.5',
            'hostname' => 'orbit-docs.beast',
            'certificate_mode' => 'orbit',
            'status' => 'active',
            'failed_step' => null,
            'error_code' => null,
        ], '0198e15c-bf97-7c23-8f1f-61b8fe67a844');

        expect($response->toArray())->toBe([
            'id' => 7,
            'app_id' => 3,
            'node_id' => 4,
            'name' => 'main',
            'environment' => 'development',
            'checkout_path' => '/home/orbit/apps/orbit-docs',
            'document_root' => 'public',
            'php_version' => '8.5',
            'hostname' => 'orbit-docs.beast',
            'certificate_mode' => 'orbit',
            'status' => 'active',
            'failed_step' => null,
            'error_code' => null,
            'request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
        ]);
    });

    it('uses safe values for invalid gateway fields', function (): void {
        $response = InstanceResponse::fromGatewayData([
            'id' => 'invalid',
            'failed_step' => ['invalid'],
        ], 'request-id');

        expect($response->id)
            ->toBe(0)
            ->and($response->failedStep)
            ->toBeNull();
    });
});
