<?php

declare(strict_types=1);

use Orbit\Sdk\Responses\Apps\AppResponse;

describe(AppResponse::class, function (): void {
    it('maps every public app field from gateway data', function (): void {
        $response = AppResponse::fromGatewayData([
            'id' => 3,
            'name' => 'Orbit Docs',
            'slug' => 'orbit-docs',
            'type' => 'laravel-app',
            'repository_url' => 'git@github.com:nckrtl/orbit-docs.git',
            'default_branch' => 'main',
            'root' => 'public',
            'task_check' => 'composer check',
        ], '0198e15c-bf97-7c23-8f1f-61b8fe67a844');

        expect($response->toArray())->toBe([
            'id' => 3,
            'name' => 'Orbit Docs',
            'slug' => 'orbit-docs',
            'type' => 'laravel-app',
            'repository_url' => 'git@github.com:nckrtl/orbit-docs.git',
            'default_branch' => 'main',
            'root' => 'public',
            'task_check' => 'composer check',
            'request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
        ]);
    });

    it('normalizes invalid optional source defaults to null', function (): void {
        $response = AppResponse::fromGatewayData(['default_branch' => ['invalid'], 'root' => ['invalid']], 'request-id');

        expect($response->defaultBranch)
            ->toBeNull()
            ->and($response->root)
            ->toBeNull();
    });
});
