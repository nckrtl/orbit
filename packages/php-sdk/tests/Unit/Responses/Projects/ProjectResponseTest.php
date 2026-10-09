<?php

declare(strict_types=1);

use Orbit\Sdk\Responses\Projects\ProjectResponse;

describe(ProjectResponse::class, function (): void {
    it('maps every public project field from gateway data', function (): void {
        $response = ProjectResponse::fromGatewayData([
            'id' => 3,
            'name' => 'Orbit Docs',
            'slug' => 'orbit-docs',
            'type' => 'laravel-app',
            'repository_url' => 'git@github.com:nckrtl/orbit-docs.git',
            'source_access' => 'gh_cli',
            'default_branch' => 'main',
            'apps' => [
                ['name' => 'web', 'path' => '.', 'web_root' => 'public', 'type' => 'laravel-app'],
                ['name' => 'docs', 'path' => 'docs', 'web_root' => null, 'type' => 'node-app'],
            ],
            'task_check' => 'composer check',
            'task_workspace_routed' => false,
            'task_compute' => 'vm',
        ], '0198e15c-bf97-7c23-8f1f-61b8fe67a844');

        expect($response->toArray())->toBe([
            'id' => 3,
            'name' => 'Orbit Docs',
            'slug' => 'orbit-docs',
            'type' => 'laravel-app',
            'repository_url' => 'git@github.com:nckrtl/orbit-docs.git',
            'source_access' => 'gh_cli',
            'default_branch' => 'main',
            'apps' => [
                ['name' => 'web', 'path' => '.', 'web_root' => 'public', 'type' => 'laravel-app'],
                ['name' => 'docs', 'path' => 'docs', 'web_root' => null, 'type' => 'node-app'],
            ],
            'task_check' => 'composer check',
            'task_workspace_routed' => false,
            'task_compute' => 'vm',
            'request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844',
        ]);
    });

    it('normalizes invalid optional source defaults to null', function (): void {
        $response = ProjectResponse::fromGatewayData(['default_branch' => ['invalid'], 'apps' => 'invalid'], 'request-id');

        expect($response->defaultBranch)
            ->toBeNull()
            ->and($response->apps)
            ->toBe([]);
    });
});
