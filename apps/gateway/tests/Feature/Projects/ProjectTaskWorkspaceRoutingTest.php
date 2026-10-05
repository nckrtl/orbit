<?php

declare(strict_types=1);

use App\Domain\Projects\ProjectType;
use App\Domain\Routes\RouteProvenance;
use App\Domain\Routes\RoutePublication;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskExecutionMode;
use App\Models\Activity;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\ProjectUpdate;
use App\Models\Route;
use App\Models\Task;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/../../Support/RuntimeGuardIsolation.php';
use Illuminate\Support\Str;

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

it('stores true when creation omits routing and false when creation sends false', function (): void {
    $routed = $this->postJson('/api/v1/projects', routing_project_payload('orbit'))
        ->assertCreated()
        ->assertJsonPath('data.task_workspace_routed', true)
        ->assertJsonPath('data.slug', 'orbit');

    $unrouted = $this->postJson('/api/v1/projects', [
        ...routing_project_payload('shop'),
        'task_workspace_routed' => false,
        'task_check' => 'composer check',
    ])->assertCreated()
        ->assertJsonPath('data.task_workspace_routed', false)
        ->assertJsonPath('data.task_check', 'composer check');

    $this->getJson('/api/v1/projects/'.$unrouted->json('data.id'))
        ->assertOk()
        ->assertJsonPath('data.task_workspace_routed', false);

    $listed = collect($this->getJson('/api/v1/projects')->assertOk()->json('data'))->keyBy('slug');

    expect($listed['orbit']['task_workspace_routed'])->toBeTrue()
        ->and($listed['shop']['task_workspace_routed'])->toBeFalse()
        ->and(Project::query()->findOrFail($routed->json('data.id'))->task_workspace_routed)->toBeTrue()
        ->and(Project::query()->findOrFail($unrouted->json('data.id'))->task_workspace_routed)->toBeFalse();
});

it('keeps a stored value when a create retry omits routing and conflicts when the sent boolean differs', function (): void {
    $created = $this->postJson('/api/v1/projects', [
        ...routing_project_payload('kept'),
        'task_workspace_routed' => false,
    ])->assertCreated()
        ->assertJsonPath('data.task_workspace_routed', false);

    $this->postJson('/api/v1/projects', routing_project_payload('kept'))
        ->assertOk()
        ->assertJsonPath('data.id', $created->json('data.id'))
        ->assertJsonPath('data.task_workspace_routed', false);

    $this->postJson('/api/v1/projects', [
        ...routing_project_payload('kept'),
        'task_workspace_routed' => false,
    ])->assertOk()
        ->assertJsonPath('data.id', $created->json('data.id'));

    $this->postJson('/api/v1/projects', [
        ...routing_project_payload('kept'),
        'task_workspace_routed' => true,
    ])->assertConflict()
        ->assertJsonPath('error.code', 'project.identity_conflict');

    expect(Project::query()->where('slug', 'kept')->count())->toBe(1)
        ->and(Project::query()->where('slug', 'kept')->sole()->task_workspace_routed)->toBeFalse();
});

it('leaves routing unchanged when an update omits it, including a rename, and changes it when sent', function (): void {
    $created = $this->postJson('/api/v1/projects', [
        ...routing_project_payload('rename-me'),
        'task_workspace_routed' => false,
        'task_check' => 'composer check',
    ])->assertCreated();
    $id = $created->json('data.id');

    $this->patchJson('/api/v1/projects/'.$id, ['code' => 'ZZZ'])
        ->assertOk()
        ->assertJsonPath('data.task_workspace_routed', false)
        ->assertJsonPath('data.task_check', 'composer check');

    $this->patchJson('/api/v1/projects/'.$id, ['slug' => 'renamed'])
        ->assertOk()
        ->assertJsonPath('data.slug', 'renamed')
        ->assertJsonPath('data.task_workspace_routed', false)
        ->assertJsonPath('data.task_check', 'composer check');

    $this->patchJson('/api/v1/projects/'.$id, [
        'slug' => 'renamed-again',
        'task_workspace_routed' => true,
    ])->assertOk()
        ->assertJsonPath('data.slug', 'renamed-again')
        ->assertJsonPath('data.task_workspace_routed', true)
        ->assertJsonPath('data.task_check', 'composer check');

    expect(Project::query()->findOrFail($id)->task_workspace_routed)->toBeTrue()
        ->and(Project::query()->findOrFail($id)->taskCheckCommand())->toBe('composer check');
});

it('rejects null, strings, and numbers for task workspace routing', function (string $method, mixed $value): void {
    $created = $this->postJson('/api/v1/projects', [
        ...routing_project_payload('validated'),
        'task_workspace_routed' => false,
        'task_check' => 'composer check',
    ])->assertCreated();

    $payload = $method === 'post'
        ? [...routing_project_payload('rejected'), 'task_workspace_routed' => $value]
        : ['task_workspace_routed' => $value];
    $url = $method === 'post' ? '/api/v1/projects' : '/api/v1/projects/'.$created->json('data.id');

    $this->json($method, $url, $payload)
        ->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation.failed')
        ->assertJsonPath('error.details.task_workspace_routed.0', 'The task workspace routed field must be true or false.');

    expect(Project::query()->where('slug', 'rejected')->exists())->toBeFalse()
        ->and(Project::query()->findOrFail($created->json('data.id'))->task_workspace_routed)->toBeFalse()
        ->and(Project::query()->findOrFail($created->json('data.id'))->task_check)->toBe('composer check');
})->with([
    'create null' => ['post', null],
    'create string' => ['post', 'false'],
    'create numeric string' => ['post', '1'],
    'create zero' => ['post', 0],
    'create one' => ['post', 1],
    'update null' => ['patch', null],
    'update string' => ['patch', 'true'],
    'update numeric string' => ['patch', '0'],
    'update zero' => ['patch', 0],
    'update one' => ['patch', 1],
]);

it('does not reconcile sources when an update only changes task workspace routing', function (): void {
    $created = $this->postJson('/api/v1/projects', [
        ...routing_project_payload('settings-only'),
        'task_check' => 'composer check',
    ])->assertCreated();
    $id = $created->json('data.id');
    $updates = ProjectUpdate::query()->count();

    $this->patchJson('/api/v1/projects/'.$id, ['task_workspace_routed' => false])
        ->assertOk()
        ->assertJsonPath('data.task_workspace_routed', false)
        ->assertJsonPath('data.task_check', 'composer check')
        ->assertJsonPath('data.repository_url', 'https://github.com/acme/settings-only.git')
        ->assertJsonPath('data.slug', 'settings-only');

    expect(ProjectUpdate::query()->count())->toBe($updates)
        ->and(Activity::query()->latest('id')->first()?->properties['input'] ?? null)
        ->toBe(['task_workspace_routed' => false])
        ->and(Project::query()->findOrFail($id)->task_check)->toBe('composer check');
});

it('seeds legacy orbit Projects unrouted, preserves task checks, and backfills workspace mode from state and Routes', function (): void {
    $node = Node::query()->create([
        'name' => 'routing-node',
        'status' => LifecycleStatus::Active,
        'public_ssh_host' => '192.0.2.40',
        'wireguard_ip' => '10.44.0.40',
    ]);
    $orbit = Project::query()->create([
        'name' => 'Orbit',
        'slug' => 'orbit',
        'type' => ProjectType::Monorepo,
        'repository_url' => 'https://github.com/orbit/orbit.git',
        'default_branch' => 'main',
        'root' => 'apps/web/public',
        'task_check' => 'composer check',
    ]);
    $shop = Project::query()->create([
        'name' => 'Shop',
        'slug' => 'shop',
        'repository_url' => 'https://github.com/acme/shop.git',
        'default_branch' => 'main',
        'root' => 'public',
        'task_check' => 'vp run check',
    ]);
    $plain = Project::query()->create([
        'name' => 'Plain',
        'slug' => 'plain',
        'repository_url' => 'https://github.com/acme/plain.git',
        'default_branch' => 'main',
        'root' => 'public',
        'task_check' => null,
    ]);

    $settledUnrouted = routing_task_workspace($orbit, $node, 'source_resolved', null, false, 'unrouted.test');
    $settledRouted = routing_task_workspace($shop, $node, 'active', 'public', true, 'routed.test');
    $activeWithoutRoute = routing_task_workspace($shop, $node, 'active', null, false, 'active-only.test');
    $provisioningRouted = routing_task_workspace($shop, $node, 'source_resolved', 'public', false, 'provisioning-routed.test');
    $provisioningUnrouted = routing_task_workspace($orbit, $node, 'reserved', null, false, 'provisioning-unrouted.test');

    $ordinary = Instance::query()->create([
        'project_id' => $shop->id,
        'node_id' => $node->id,
        'name' => 'default',
        'checkout_path' => '/srv/orbit/apps/shop/default',
        'root' => 'public',
        'status' => 'active',
    ]);
    $ordinaryRoute = Route::query()->create([
        'project_id' => $shop->id,
        'node_id' => $node->id,
        'domain' => 'shop.test',
        'provenance' => RouteProvenance::Explicit,
        'publication' => RoutePublication::Private,
    ]);
    $ordinaryRoute->targets()->create(['instance_id' => $ordinary->id, 'position' => 0]);
    $annotation = Task::topLevel()->create([
        'project_id' => $shop->id,
        'title' => 'Existing thread',
        'brief' => 'An ordinary Instance.',
        'status' => 'running',
        'execution_mode' => TaskExecutionMode::ExistingThread,
    ]);
    $annotation->taskable()->associate($ordinary);
    $annotation->save();

    $mismatched = Instance::query()->create([
        'project_id' => $shop->id,
        'node_id' => $node->id,
        'name' => 'not-a-workspace',
        'checkout_path' => '/srv/orbit/apps/shop/not-a-workspace',
        'status' => 'source_resolved',
    ]);
    $managed = Task::topLevel()->create([
        'project_id' => $shop->id,
        'title' => 'Mismatched name',
        'brief' => 'Not this Instance.',
        'status' => 'running',
    ]);
    $managed->taskable()->associate($mismatched);
    $managed->save();

    $migration = require database_path('migrations/2026_10_06_000000_add_task_workspace_routing.php');
    assert($migration instanceof Migration);
    owned_interrupted_creation_removal_migration()->down();
    $guards = take_app_runtime_guards();
    try {
        $migration->down();
        $migration->up();
    } finally {
        restore_app_runtime_guards($guards);
        owned_interrupted_creation_removal_migration()->up();
    }

    expect(Project::query()->findOrFail($orbit->id)->task_workspace_routed)->toBeFalse()
        ->and(Project::query()->findOrFail($orbit->id)->task_check)->toBe('composer check')
        ->and(Project::query()->findOrFail($shop->id)->task_workspace_routed)->toBeTrue()
        ->and(Project::query()->findOrFail($shop->id)->task_check)->toBe('vp run check')
        ->and(Project::query()->findOrFail($plain->id)->task_workspace_routed)->toBeTrue()
        ->and(Project::query()->findOrFail($plain->id)->task_check)->toBeNull()
        ->and(Instance::query()->findOrFail($settledUnrouted->id)->task_workspace_routed)->toBeFalse()
        ->and(Instance::query()->findOrFail($settledRouted->id)->task_workspace_routed)->toBeTrue()
        ->and(Instance::query()->findOrFail($activeWithoutRoute->id)->task_workspace_routed)->toBeTrue()
        ->and(Instance::query()->findOrFail($provisioningRouted->id)->task_workspace_routed)->toBeTrue()
        ->and(Instance::query()->findOrFail($provisioningUnrouted->id)->task_workspace_routed)->toBeFalse()
        ->and(Instance::query()->findOrFail($ordinary->id)->task_workspace_routed)->toBeNull()
        ->and(Instance::query()->findOrFail($mismatched->id)->task_workspace_routed)->toBeNull();

    DB::table('projects')->insert([
        'name' => 'Fresh',
        'slug' => 'fresh',
        'code' => 'FRS',
        'type' => 'laravel-app',
        'repository_url' => 'https://github.com/acme/fresh.git',
        'repository_identity' => 'github.com/acme/fresh',
        'task_check' => 'composer check',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect((bool) DB::table('projects')->where('slug', 'fresh')->value('task_workspace_routed'))->toBeTrue()
        ->and(DB::table('projects')->where('slug', 'fresh')->value('task_check'))->toBe('composer check');
});

/**
 * @return array{slug: string, type: string, repository_url: string, default_branch: string, root: string}
 */
function routing_project_payload(string $slug): array
{
    return [
        'slug' => $slug,
        'type' => ProjectType::LaravelApp->value,
        'repository_url' => 'https://github.com/acme/'.$slug.'.git',
        'default_branch' => 'main',
        'root' => 'public',
    ];
}

function routing_task_workspace(Project $project, Node $node, string $status, ?string $root, bool $withRoute, string $domain): Instance
{
    $instance = Instance::query()->create([
        'project_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'pending-'.Str::uuid()->toString(),
        'checkout_path' => '/srv/orbit/apps/'.$project->slug.'/'.Str::uuid()->toString(),
        'root' => $root,
        'status' => $withRoute ? 'active' : $status,
    ]);
    $task = Task::topLevel()->create([
        'project_id' => $project->id,
        'title' => 'Workspace '.$domain,
        'brief' => 'Record the provisioned mode.',
        'status' => 'running',
    ]);
    $name = 'task-'.$task->id;
    $attributes = ['name' => $name, 'branch_override' => $name];

    if (! $withRoute) {
        $attributes['status'] = $status;
    }

    $instance->update($attributes);
    $task->taskable()->associate($instance);
    $task->save();

    if ($withRoute) {
        $route = Route::query()->create([
            'project_id' => $project->id,
            'node_id' => $node->id,
            'domain' => $domain,
            'provenance' => RouteProvenance::Explicit,
            'publication' => RoutePublication::Private,
        ]);
        $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
    }

    return $instance;
}
