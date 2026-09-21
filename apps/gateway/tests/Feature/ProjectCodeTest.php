<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskExtensionState;
use App\Models\App as OrbitApp;
use App\Models\Node;

beforeEach(function (): void {
    $operator = Node::query()->create([
        'name' => 'code-operator', 'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.2', 'wireguard_ip' => '10.44.0.2',
    ]);
    $this->markAsGateway($operator);
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2']);
});

function projectForCode(string $slug): OrbitApp
{
    return OrbitApp::query()->create([
        'name' => $slug, 'slug' => $slug, 'repository_url' => "https://github.com/example/{$slug}.git",
        'default_branch' => 'main', 'root' => 'public',
    ]);
}

it('persists an editable code and returns the new code on task cards', function (): void {
    app(TaskExtensionState::class)->enable();
    $project = projectForCode('orbit');
    expect($project->code)->toBe('ORB');
    $group = $project->taskGroups()->create([
        'title' => 'Code on card', 'brief' => 'Brief', 'status' => 'queued',
        'implementer_model' => 'model', 'reviewer_model' => 'model',
    ]);
    $this->patchJson("/api/v1/projects/{$project->id}", ['code' => 'NEW'])
        ->assertOk()->assertJsonPath('data.code', 'NEW');
    expect($project->fresh()?->code)->toBe('NEW');
    $this->getJson("/api/v1/task-groups/{$group->id}")->assertOk()->assertJsonPath('data.project_code', 'NEW');
});

it('rejects a duplicate or invalid code without changing the project', function (): void {
    $first = projectForCode('orbit');
    $second = projectForCode('commander');
    $this->patchJson("/api/v1/projects/{$second->id}", ['code' => $first->code])->assertConflict();
    foreach (['ab', 'abcd', 'AbC', 'A12', ''] as $invalid) {
        $this->patchJson("/api/v1/projects/{$second->id}", ['code' => $invalid])->assertUnprocessable();
    }
    $this->patchJson("/api/v1/projects/{$second->id}", ['code' => 'NEW', 'slug' => 'renamed'])->assertUnprocessable();
    expect($second->fresh()?->code)->toBe('COM');
});

it('backfills distinct codes and prioritizes ORB for Orbit', function (): void {
    $other = projectForCode('orbital');
    $orbit = projectForCode('orbit');
    $migration = require database_path('migrations/2026_09_21_132029_add_code_to_apps.php');
    $migration->down();
    $migration->up();
    expect($orbit->fresh()?->code)->toBe('ORB')
        ->and($other->fresh()?->code)->toMatch('/^[A-Z]{3}$/')
        ->not->toBe('ORB');
});

it('accepts an explicit code on creation and rejects another project claiming it', function (): void {
    $this->fakeRepositoryBranches();
    $payload = [
        'slug' => 'custom-project', 'code' => 'CUS', 'type' => 'laravel-app',
        'repository_url' => 'https://github.com/example/custom-project.git',
        'default_branch' => 'main', 'root' => 'public',
    ];
    $this->postJson('/api/v1/projects', $payload)->assertCreated()->assertJsonPath('data.code', 'CUS');
    $this->postJson('/api/v1/projects', $payload)->assertOk()->assertJsonPath('data.code', 'CUS');
    $payload['slug'] = 'other-project';
    $payload['repository_url'] = 'https://github.com/example/other-project.git';
    $this->postJson('/api/v1/projects', $payload)->assertConflict();
});

it('allocates another code when a concurrent project claims the suggested code', function (): void {
    $this->fakeRepositoryBranches();
    $claimed = false;
    OrbitApp::creating(static function (OrbitApp $project) use (&$claimed): void {
        if ($claimed || $project->slug !== 'orbit') {
            return;
        }
        $claimed = true;
        projectForCode('orbital');
    });

    $this->postJson('/api/v1/projects', [
        'slug' => 'orbit', 'type' => 'laravel-app',
        'repository_url' => 'https://github.com/example/orbit.git',
        'default_branch' => 'main', 'root' => 'public',
    ])->assertCreated();

    expect(OrbitApp::query()->where('slug', 'orbital')->sole()->code)->toBe('ORB')
        ->and(OrbitApp::query()->where('slug', 'orbit')->sole()->code)->toMatch('/^[A-Z]{3}$/')->not->toBe('ORB');
});
