<?php

declare(strict_types=1);

use App\Models\AppInstanceEnvironmentValue;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('encrypts environment values and scopes unique keys to one AppInstance', function (): void {
    [$first, $second] = environment_database_instances();
    $literal = $first->environmentValues()->create(['env_key' => 'APP_KEY', 'env_value' => 'plain-literal']);
    $placeholder = $first
        ->environmentValues()
        ->create([
            'env_key' => 'APP_URL',
            'env_value' => 'https://{{app_instance.hostname}}',
        ]);
    $second->environmentValues()->create(['env_key' => 'APP_KEY', 'env_value' => 'other-instance']);

    expect(fn () => $first->environmentValues()->create(['env_key' => 'APP_KEY', 'env_value' => 'duplicate']))
        ->toThrow(QueryException::class);

    $raw = DB::table('app_instance_environment_values')->orderBy('id')->pluck('env_value')->all();

    expect($raw)
        ->each->toBeString()
        ->not->toContain('plain-literal', 'https://{{app_instance.hostname}}', 'other-instance')->and(
            $literal->toArray(),
        )->toHaveKeys(['id', 'app_instance_id', 'env_key', 'created_at', 'updated_at'])->and($literal->toArray())
        ->not->toHaveKey('env_value')->and(print_r($placeholder, true))
        ->not->toContain('https://{{app_instance.hostname}}');
});

it('adds no values during migration and cascades values only when the owner is deleted', function (): void {
    [$first] = environment_database_instances();

    expect(AppInstanceEnvironmentValue::query()->count())->toBe(0);

    $first->environmentValues()->create(['env_key' => 'TOKEN', 'env_value' => 'synthetic']);
    $first->delete();

    expect(AppInstanceEnvironmentValue::query()->count())->toBe(0);
});

/** @return array{\App\Models\AppInstance, \App\Models\AppInstance} */
function environment_database_instances(): array
{
    $app = \App\Models\App::query()->create([
        'name' => 'Environment database',
        'slug' => 'environment-database',
        'repository_url' => 'https://example.test/environment.git',
        'default_branch' => 'main',
        'root' => 'public',
    ]);
    $node = \App\Models\Node::query()->create([
        'name' => 'environment-node',
        'status' => 'active',
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.207',
        'wireguard_ip' => '10.44.0.207',
        'user' => 'orbit',
    ]);

    return [
        \App\Models\AppInstance::query()->create([
            'app_id' => $app->id,
            'node_id' => $node->id,
            'name' => 'first',
            'checkout_path' => '/srv/orbit/first',
        ]),
        \App\Models\AppInstance::query()->create([
            'app_id' => $app->id,
            'node_id' => $node->id,
            'name' => 'second',
            'checkout_path' => '/srv/orbit/second',
        ]),
    ];
}
