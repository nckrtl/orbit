<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Actions\Nodes\AddNodeAccessAction;
use App\Domain\Compute\SandboxSpec;
use App\Domain\Compute\SandboxState;
use App\Domain\Nodes\RoleName;
use App\Domain\Tasks\TaskCompute;
use App\Infrastructure\Compute\SandboxFleetIdentity;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskSandbox;
use Illuminate\Support\Str;

final class UpCloudRuntimeWorkspace
{
    public static function sandbox(): TaskSandbox
    {
        $cluster = Cluster::query()->create(['name' => 'Dev', 'tld' => 'test', 'state' => 'active']);
        foreach ([['hub', '10.44.0.1', '93.184.216.35', RoleName::Vpn], ['gateway', '10.44.0.2', '93.184.216.34', RoleName::Gateway],
            ['router', '10.44.0.9', '93.184.216.36', RoleName::Router], ['model', '10.44.0.3', '93.184.216.37', null]] as [$name, $address, $public, $role]) {
            $node = Node::query()->create(['name' => $name, 'cluster_id' => $role === RoleName::Router ? $cluster->id : null,
                'status' => 'active', 'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64', 'wireguard_ip' => $address, 'public_ssh_host' => $public]);
            if ($role !== null) {
                $node->roles()->create(['role' => $role, 'cluster_id' => $role === RoleName::Router ? $cluster->id : null, 'status' => 'active']);
            }
        }
        config(['compute.upcloud.enrollment_enabled' => true, 'compute.upcloud.dev_cluster_id' => $cluster->id,
            'compute.upcloud.model_address' => '10.44.0.3', 'compute.upcloud.model_port' => 8317]);
        $project = Project::query()->create(['name' => 'DLF', 'slug' => 'dlf', 'repository_url' => 'https://github.com/acme/dlf.git', 'default_branch' => 'main']);
        $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Work', 'brief' => 'Work', 'status' => 'todo', 'task_compute' => TaskCompute::Vm]);
        $id = (string) Str::uuid();

        return TaskSandbox::query()->create(['id' => $id, 'group_id' => $group->id, 'name' => 'orbit-sandbox-'.$id,
            'provider' => 'upcloud', 'state' => SandboxState::Running, 'desired_power' => 'running',
            'server_id' => (string) Str::uuid(), 'disk_id' => (string) Str::uuid(), 'public_address' => '93.184.216.38',
            'spec' => (new SandboxSpec('nl-ams1', '93.184.216.34', '93.184.216.35', 51820, 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFakePublicMaterial'))->toArray()]);
    }

    public static function create(): Instance
    {
        $sandbox = self::sandbox();
        $node = app(SandboxFleetIdentity::class)->reserve($sandbox);
        $node->update(['status' => 'active']);
        app(AddNodeAccessAction::class)->execute($node, $node);
        $node->roles()->create(['role' => 'app-dev', 'status' => 'active']);
        $sandbox->forceFill(['network_policy' => 'sealed', 'enrollment' => [...$sandbox->enrollment, 'hub_confirmed_at' => now()->toIso8601String()], 'enrolled_at' => now(), 'pi_token' => str_repeat('a', 64), 'model_key' => str_repeat('b', 64),
            'model_key_registered_at' => now(), 'model_proxy_origin' => 'http://10.44.0.3:8317', 'pi_ready_at' => now()])->save();
        $workspace = Instance::query()->create(['project_id' => $sandbox->group->project_id, 'node_id' => $node->id,
            'name' => 'task-'.$sandbox->group_id, 'branch_override' => 'task-'.$sandbox->group_id, 'task_workspace_routed' => false, 'checkout_path' => '/home/orbit/orbit', 'task_sandbox_id' => $sandbox->id]);
        $sandbox->group->taskable()->associate($workspace);
        $sandbox->group->save();

        return $workspace;
    }
}
