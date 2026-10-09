<?php

declare(strict_types=1);

use App\Models\Instance;
use App\Models\InstanceEnvironmentValue;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('encrypts environment values and scopes unique keys to one Instance', function (): void {
    [$first, $second] = environment_database_instances();
    $literal = $first->environmentValues()->create(['env_key' => 'APP_KEY', 'env_value' => 'plain-literal']);
    $placeholder = $first
        ->environmentValues()
        ->create([
            'env_key' => 'APP_URL',
            'env_value' => 'https://{{instance.domain}}',
        ]);
    $second->environmentValues()->create(['env_key' => 'APP_KEY', 'env_value' => 'other-instance']);

    expect(fn () => $first->environmentValues()->create(['env_key' => 'APP_KEY', 'env_value' => 'duplicate']))
        ->toThrow(QueryException::class);

    $raw = DB::table('instance_environment_values')->orderBy('id')->pluck('env_value')->all();

    expect($raw)
        ->each->toBeString()
        ->not->toContain('plain-literal', 'https://{{instance.domain}}', 'other-instance')->and(
            $literal->toArray(),
        )->toHaveKeys(['id', 'instance_id', 'env_key', 'created_at', 'updated_at'])->and($literal->toArray())
        ->not->toHaveKey('env_value')->and(print_r($placeholder, true))
        ->not->toContain('https://{{instance.domain}}');
});

it('adds no values during migration and cascades values only when the owner is deleted', function (): void {
    [$first] = environment_database_instances();

    expect(InstanceEnvironmentValue::query()->count())->toBe(0);

    $first->environmentValues()->create(['env_key' => 'TOKEN', 'env_value' => 'synthetic']);
    $first->delete();

    expect(InstanceEnvironmentValue::query()->count())->toBe(0);
});

/** @return array{Instance, Instance} */
function environment_database_instances(): array
{
    $project = Project::query()->create([
        'name' => 'Environment database',
        'slug' => 'environment-database',
        'repository_url' => 'https://example.test/environment.git',
        'default_branch' => 'main',
        'apps' => fixture_apps('public'),
    ]);
    $node = Node::query()->create([
        'name' => 'environment-node',
        'status' => 'active',
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.207',
        'wireguard_ip' => '10.44.0.207',
        'user' => 'orbit',
    ]);

    return [
        Instance::query()->create([
            'project_id' => $project->id,
            'node_id' => $node->id,
            'name' => 'first',
            'checkout_path' => '/srv/orbit/first',
        ]),
        Instance::query()->create([
            'project_id' => $project->id,
            'node_id' => $node->id,
            'name' => 'second',
            'checkout_path' => '/srv/orbit/second',
        ]),
    ];
}
