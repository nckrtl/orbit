<?php

declare(strict_types=1);

use App\Actions\Instances\CreateInstanceAction;
use App\Data\Instances\CreateInstanceData;
use App\Domain\Instances\InstanceState;
use App\Domain\Instances\ProductionInstanceProvisioner;
use App\Domain\Nodes\RoleName;
use App\Domain\Projects\ProjectType;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Project;
use App\Models\ProjectNodeExclusion;

beforeEach(function (): void {
    $this->operator = Node::query()->create([
        'name' => 'operator',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.2',
        'wireguard_ip' => '10.44.0.2',
    ]);
    $this->operator->roles()->create(['role' => RoleName::Gateway, 'status' => LifecycleStatus::Active]);
    $this->operator = $this->markAsGateway($this->operator);
    $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.2']);

    $this->project = Project::query()->create([
        'name' => 'Orbit',
        'slug' => 'orbit',
        'type' => ProjectType::Monorepo,
        'repository_url' => 'https://github.com/nckrtl/orbit.git',
        'default_branch' => 'main',
        'apps' => fixture_apps('public', ProjectType::Monorepo),
    ]);
    $this->sabre = exclusion_node('sabre', '10.44.0.31');
    $this->shark = exclusion_node('shark', '10.44.0.32');
});

it('stores one exclusion row from either the project or the node', function (): void {
    $created = $this->postJson("/api/v1/projects/{$this->project->id}/excluded-nodes/{$this->sabre->id}")
        ->assertCreated()
        ->assertJsonPath('data.project_slug', 'orbit')
        ->assertJsonPath('data.node_name', 'sabre')
        ->assertJsonPath('data.already_exists', false)
        ->assertJsonPath('data.development_instance_count', 0);

    $this->postJson("/api/v1/nodes/{$this->sabre->id}/excluded-projects/{$this->project->id}")
        ->assertOk()
        ->assertJsonPath('data.already_exists', true)
        ->assertJsonPath('data.project_id', $created->json('data.project_id'));

    expect(ProjectNodeExclusion::query()->count())->toBe(1);

    $this->getJson("/api/v1/projects/{$this->project->id}/excluded-nodes")
        ->assertOk()
        ->assertJsonPath('data.0.node_name', 'sabre');

    $this->getJson("/api/v1/nodes/{$this->sabre->id}/excluded-projects")
        ->assertOk()
        ->assertJsonPath('data.0.project_slug', 'orbit');

    $this->getJson("/api/v1/projects/{$this->project->id}")
        ->assertOk()
        ->assertJsonPath('data.excluded_nodes.0.node_name', 'sabre');

    $this->getJson("/api/v1/nodes/{$this->sabre->id}")
        ->assertOk()
        ->assertJsonPath('data.excluded_projects.0.project_slug', 'orbit');
});

it('refuses a node that has no active app-dev role and a missing pair', function (): void {
    $this->sabre->roles()->update(['status' => LifecycleStatus::Failed]);

    $this->postJson("/api/v1/projects/{$this->project->id}/excluded-nodes/{$this->sabre->id}")
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'project.excluded_node_not_app_dev');

    $this->sabre->roles()->update(['status' => LifecycleStatus::Active]);

    $this->deleteJson("/api/v1/nodes/{$this->sabre->id}/excluded-projects/{$this->project->id}")
        ->assertNotFound()
        ->assertJsonPath('error.code', 'project.excluded_node_missing');
});

it('counts development instances already on the excluded node and leaves them there', function (): void {
    Instance::query()->create([
        'project_id' => $this->project->id,
        'node_id' => $this->sabre->id,
        'name' => 'existing',
        'checkout_path' => '/srv/orbit/apps/orbit/existing',
        'status' => InstanceState::Active,
        'environment' => 'development',
    ]);

    $this->postJson("/api/v1/projects/{$this->project->id}/excluded-nodes/{$this->sabre->id}")
        ->assertCreated()
        ->assertJsonPath('data.development_instance_count', 1);

    expect(Instance::query()->where('node_id', $this->sabre->id)->count())->toBe(1);
});

it('deletes exclusion rows when the app-dev role is removed and does not restore them', function (): void {
    ProjectNodeExclusion::query()->create(['project_id' => $this->project->id, 'node_id' => $this->sabre->id]);
    $role = NodeRole::query()->where('node_id', $this->sabre->id)->where('role', RoleName::AppDev)->firstOrFail();

    $role->delete();

    expect(ProjectNodeExclusion::query()->count())->toBe(0);

    $this->sabre->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);

    expect(ProjectNodeExclusion::query()->count())->toBe(0);
});

it('refuses a new development instance on an excluded node', function (): void {
    ProjectNodeExclusion::query()->create(['project_id' => $this->project->id, 'node_id' => $this->sabre->id]);

    expect(fn () => app(CreateInstanceAction::class)->execute(new CreateInstanceData(
        projectId: $this->project->id,
        nodeId: $this->sabre->id,
        name: 'feature',
        appOverrides: fixture_app_overrides('public'),
        domain: null,
        branch: 'main',
    )))->toThrow(ResourceOperationException::class, 'cannot use Node [sabre]');

    expect(Instance::query()->where('name', 'feature')->exists())->toBeFalse();
});

it('still places a production instance when the node is excluded for development', function (): void {
    $this->sabre->roles()->create(['role' => RoleName::AppProd, 'status' => LifecycleStatus::Active]);
    ProjectNodeExclusion::query()->create(['project_id' => $this->project->id, 'node_id' => $this->sabre->id]);
    $provisioner = new class implements ProductionInstanceProvisioner
    {
        public bool $called = false;

        public function execute(CreateInstanceData $data, Project $project, Node $node, array $appOverrides): array
        {
            $this->called = true;
            $instance = Instance::query()->create([
                'project_id' => $project->id,
                'node_id' => $node->id,
                'name' => $data->name,
                'checkout_path' => '/srv/orbit/apps/orbit/'.$data->name,
                'status' => InstanceState::Reserved,
                'environment' => 'production',
            ]);

            return ['instance' => $instance, 'created' => true];
        }
    };
    app()->instance(ProductionInstanceProvisioner::class, $provisioner);

    $result = app(CreateInstanceAction::class)->execute(new CreateInstanceData(
        projectId: $this->project->id,
        nodeId: $this->sabre->id,
        name: 'release',
        appOverrides: fixture_app_overrides('public'),
        domain: 'orbit.example',
        branch: 'main',
    ));

    expect($provisioner->called)->toBeTrue()
        ->and($result['instance']->name)->toBe('release');
});

function exclusion_node(string $name, string $ip): Node
{
    $node = Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.'.substr($ip, -2),
        'wireguard_ip' => $ip,
    ]);
    $node->roles()->create(['role' => RoleName::AppDev, 'status' => LifecycleStatus::Active]);

    return $node;
}
