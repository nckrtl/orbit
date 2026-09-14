<?php

declare(strict_types=1);

use App\Domain\Instances\CertificateMode;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Models\App as OrbitApp;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Workspace;

describe('retired workspace API', function (): void {
    beforeEach(function (): void {
        $this->node = Node::query()->create([
            'name' => 'app-dev',
            'tld' => 'app-dev.orbit',
            'status' => LifecycleStatus::Active,
            'public_ssh_host' => '192.0.2.10',
            'user' => 'orbit',
            'wireguard_ip' => '10.44.0.3',
        ]);
        $this->node
            ->roles()
            ->create([
                'role' => RoleName::AppDev,
                'status' => LifecycleStatus::Active,
            ]);
        $this->node->accessibleNodes()->attach($this->node);
        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.3']);
        $app = OrbitApp::query()->create([
            'name' => 'Acme',
            'slug' => 'acme',
            'repository_url' => 'git@github.com:acme/site.git',
        ]);
        $this->instance = Instance::query()->create([
            'app_id' => $app->id,
            'node_id' => $this->node->id,
            'name' => 'dev',
            'environment' => 'development',
            'checkout_path' => '/srv/users/nckrtl/apps/acme',
            'hostname' => 'acme.app-dev.orbit',
            'certificate_mode' => CertificateMode::OrbitCa,
            'status' => LifecycleStatus::Active,
        ]);
        $this->workspace = Workspace::query()->create([
            'instance_id' => $this->instance->id,
            'name' => 'feature-one',
            'branch' => 'feature-one',
            'checkout_path' => '/srv/orbit/workspaces/acme/feature-one',
            'hostname' => 'feature-one.app-dev.orbit',
            'status' => LifecycleStatus::Active,
        ]);
    });

    it('leaves Workspace records unchanged when a retired endpoint is invoked', function (string $method, string $uri, array $payload): void {
        $before = $this->workspace->only(['id', 'instance_id', 'name', 'checkout_path', 'status']);
        $resolvedUri = str_replace('{id}', (string) $this->workspace->id, $uri);
        $resolvedPayload = isset($payload['instance_id'])
            ? [...$payload, 'instance_id' => $this->instance->id]
            : $payload;

        $this
            ->json($method, $resolvedUri, $resolvedPayload)
            ->assertNotFound();

        expect($this->workspace->refresh()->only(['id', 'instance_id', 'name', 'checkout_path', 'status']))
            ->toBe($before)
            ->and(Workspace::query()->count())
            ->toBe(1);
    })->with([
        'list' => ['GET', '/api/v1/workspaces', []],
        'show' => ['GET', '/api/v1/workspaces/{id}', []],
        'create' => ['POST', '/api/v1/workspaces', [
            'instance_id' => '{instance}',
            'name' => 'feature-two',
        ]],
        'remove' => ['DELETE', '/api/v1/workspaces/{id}', []],
        'php' => ['PATCH', '/api/v1/workspaces/{id}/php', ['php_version' => '8.4']],
    ]);
});
