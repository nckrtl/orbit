<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Http\Mcp\ToolManifest;
use App\Models\Node;
use App\Models\Project;

it('exposes and executes all development deploy step tools including false required', function (): void {
    $gateway = $this->markAsGateway(Node::query()->create([
        'name' => 'gateway', 'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.1', 'wireguard_ip' => '10.44.0.1',
    ]));
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);
    $project = Project::query()->create([
        'name' => 'Acme', 'slug' => 'acme', 'repository_url' => 'https://example.test/acme.git', 'default_branch' => 'main',
        'apps' => fixture_apps(null),
    ]);
    $call = function (string $operation, array $arguments): array {
        $response = $this->postJson('/mcp', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'project-dev-deploy-step-'.$operation, 'arguments' => $arguments],
        ])->assertOk();
        expect($response->json('result.isError'))->toBeFalse();

        return json_decode($response->json('result.content.0.text'), true);
    };
    foreach (['create', 'update'] as $operation) {
        $definition = collect(ToolManifest::default()->definitions())->first(fn ($tool): bool => $tool->name === 'project-dev-deploy-step-'.$operation);
        expect($definition?->inputSchema['properties']['required']['type'])->toBe('boolean');
    }
    $created = $call('create', ['project' => $project->id, 'name' => 'cache', 'command' => 'true', 'required' => false]);
    expect($created['data']['required'])->toBeFalse();
    $updated = $call('update', ['project' => $project->id, 'step' => 'cache', 'required' => true]);
    expect($updated['data']['required'])->toBeTrue();
    $listed = $call('list', ['project' => $project->id]);
    expect($listed['data'][0]['name'])->toBe('cache');
    $removed = $call('destroy', ['project' => $project->id, 'step' => 'cache']);
    expect($removed['data']['name'])->toBe('cache');
    expect($call('list', ['project' => $project->id])['data'])->toBe([]);
});
