<?php

declare(strict_types=1);

use App\Domain\Projects\ProjectType;
use App\Domain\Shared\LifecycleStatus;
use App\Models\App as OrbitApp;
use App\Models\Node;
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

it('gives a new Project the task check of its type when none is sent', function (ProjectType $type, string $root, ?string $expected): void {
    $created = $this->postJson('/api/v1/projects', [
        'slug' => 'kit-'.$type->value,
        'type' => $type->value,
        'repository_url' => 'https://github.com/acme/'.$type->value.'.git',
        'default_branch' => 'main',
        'root' => $root,
    ])->assertCreated()
        ->assertJsonPath('data.task_check', $expected);

    $this->getJson('/api/v1/projects/'.$created->json('data.id'))
        ->assertOk()
        ->assertJsonPath('data.task_check', $expected);
})->with([
    'laravel-app' => [ProjectType::LaravelApp, 'public', 'composer check'],
    'laravel-package' => [ProjectType::LaravelPackage, '.', 'composer check'],
    'node-package' => [ProjectType::NodePackage, '.', null],
    'monorepo' => [ProjectType::Monorepo, 'apps/web/public', null],
]);

it('stores the task check a new Project sends, including null', function (): void {
    $this->postJson('/api/v1/projects', [
        'slug' => 'custom-check',
        'type' => ProjectType::NodePackage->value,
        'repository_url' => 'https://github.com/acme/custom-check.git',
        'default_branch' => 'main',
        'root' => '.',
        'task_check' => 'vp run check',
    ])->assertCreated()
        ->assertJsonPath('data.task_check', 'vp run check');

    $this->postJson('/api/v1/projects', [
        'slug' => 'no-check',
        'type' => ProjectType::LaravelApp->value,
        'repository_url' => 'https://github.com/acme/no-check.git',
        'default_branch' => 'main',
        'root' => 'public',
        'task_check' => null,
    ])->assertCreated()
        ->assertJsonPath('data.task_check', null);
});

it('still refuses a Project create without a type', function (): void {
    $this->postJson('/api/v1/projects', [
        'slug' => 'untyped',
        'repository_url' => 'https://github.com/acme/untyped.git',
        'default_branch' => 'main',
        'root' => 'public',
    ])->assertUnprocessable()
        ->assertJsonPath('error.details.type.0', 'The type field is required.');

    expect(OrbitApp::query()->where('slug', 'untyped')->exists())->toBeFalse();
});

it('backfills existing Projects with the task check of their type', function (): void {
    $migration = require database_path('migrations/2026_09_25_140000_add_task_check_to_apps.php');
    assert($migration instanceof Migration);
    $migration->down();

    foreach (ProjectType::cases() as $index => $type) {
        DB::table('apps')->insert([
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

    $migration->up();

    expect(DB::table('apps')->orderBy('slug')->pluck('task_check', 'type')->all())->toBe([
        'laravel-app' => 'composer check',
        'laravel-package' => 'composer check',
        'monorepo' => null,
        'node-package' => null,
    ]);
});
