<?php

declare(strict_types=1);

use App\Domain\Projects\ProjectType;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->operator = $this->markAsGateway(Node::query()->create([
        'name' => 'operator',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.2',
        'wireguard_ip' => '10.44.0.2',
    ]));
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2']);
    $this->fakeRepositoryBranches();
});

it('stores no task check when creation omits the command or sends null, for every type', function (ProjectType $type, string $root, bool $explicitNull): void {
    $suffix = $explicitNull ? '-null' : '';
    $payload = [
        'slug' => 'kit-'.$type->value.$suffix,
        'type' => $type->value,
        'repository_url' => 'https://github.com/acme/'.$type->value.$suffix.'.git',
        'default_branch' => 'main',
        'root' => $root,
    ];
    if ($explicitNull) {
        $payload['task_check'] = null;
    }

    $created = $this->postJson('/api/v1/projects', $payload)
        ->assertCreated()
        ->assertJsonPath('data.task_check', null);

    $this->getJson('/api/v1/projects/'.$created->json('data.id'))
        ->assertOk()
        ->assertJsonPath('data.task_check', null);

    expect(Project::query()->findOrFail($created->json('data.id'))->taskCheckCommand())->toBeNull();
})->with([
    'laravel-app omitted' => [ProjectType::LaravelApp, 'public', false],
    'laravel-app explicit null' => [ProjectType::LaravelApp, 'public', true],
    'laravel-package omitted' => [ProjectType::LaravelPackage, '.', false],
    'laravel-package explicit null' => [ProjectType::LaravelPackage, '.', true],
    'node-package omitted' => [ProjectType::NodePackage, '.', false],
    'node-package explicit null' => [ProjectType::NodePackage, '.', true],
    'monorepo omitted' => [ProjectType::Monorepo, 'apps/web/public', false],
    'monorepo explicit null' => [ProjectType::Monorepo, 'apps/web/public', true],
]);

it('stores an explicit task check, and a create retry that omits it leaves that command', function (): void {
    $this->postJson('/api/v1/projects', [
        'slug' => 'custom-check',
        'type' => ProjectType::NodePackage->value,
        'repository_url' => 'https://github.com/acme/custom-check.git',
        'default_branch' => 'main',
        'root' => '.',
        'task_check' => 'vp run check',
    ])->assertCreated()
        ->assertJsonPath('data.task_check', 'vp run check');

    $created = $this->postJson('/api/v1/projects', [
        'slug' => 'kept-check',
        'type' => ProjectType::LaravelApp->value,
        'repository_url' => 'https://github.com/acme/kept-check.git',
        'default_branch' => 'main',
        'root' => 'public',
        'task_check' => 'composer check',
    ])->assertCreated()
        ->assertJsonPath('data.task_check', 'composer check');

    $this->postJson('/api/v1/projects', [
        'slug' => 'kept-check',
        'type' => ProjectType::LaravelApp->value,
        'repository_url' => 'https://github.com/acme/kept-check.git',
        'default_branch' => 'main',
        'root' => 'public',
    ])->assertOk()
        ->assertJsonPath('data.id', $created->json('data.id'))
        ->assertJsonPath('data.task_check', 'composer check');
});

it('leaves a stored task check unchanged when an update omits it, and clears it when null is sent', function (): void {
    $created = $this->postJson('/api/v1/projects', [
        'slug' => 'update-check',
        'type' => ProjectType::LaravelPackage->value,
        'repository_url' => 'https://github.com/acme/update-check.git',
        'default_branch' => 'main',
        'root' => '.',
        'task_check' => 'vp run check',
    ])->assertCreated();
    $id = $created->json('data.id');

    $this->patchJson('/api/v1/projects/'.$id, ['code' => 'ZZZ'])
        ->assertOk()
        ->assertJsonPath('data.task_check', 'vp run check');

    expect(Project::query()->findOrFail($id)->taskCheckCommand())->toBe('vp run check');

    $this->patchJson('/api/v1/projects/'.$id, ['task_check' => null])
        ->assertOk()
        ->assertJsonPath('data.task_check', null);

    expect(Project::query()->findOrFail($id)->taskCheckCommand())->toBeNull();
});

it('still refuses a Project create without a type', function (): void {
    $this->postJson('/api/v1/projects', [
        'slug' => 'untyped',
        'repository_url' => 'https://github.com/acme/untyped.git',
        'default_branch' => 'main',
        'root' => 'public',
    ])->assertUnprocessable()
        ->assertJsonPath('error.details.type.0', 'The type field is required.');

    expect(Project::query()->where('slug', 'untyped')->exists())->toBeFalse();
});

it('gives every existing Project the composer check task check whatever its type', function (): void {
    $migration = require database_path('migrations/2026_09_25_140000_add_task_check_to_apps.php');
    assert($migration instanceof Migration);
    run_legacy_schema_migration($migration, 'down');

    foreach (ProjectType::cases() as $index => $type) {
        DB::table('projects')->insert([
            'name' => $type->value,
            'slug' => $type->value,
            'code' => 'MG'.chr(65 + $index),
            'type' => $type->value,
            'repository_url' => 'https://github.com/acme/'.$type->value.'.git',
            'repository_identity' => 'github.com/acme/'.$type->value,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    run_legacy_schema_migration($migration, 'up');

    expect(DB::table('projects')->orderBy('slug')->pluck('task_check', 'type')->all())->toBe([
        'laravel-app' => 'composer check',
        'laravel-package' => 'composer check',
        'monorepo' => 'composer check',
        'node-package' => 'composer check',
        'symfony-app' => 'composer check',
    ]);
});
