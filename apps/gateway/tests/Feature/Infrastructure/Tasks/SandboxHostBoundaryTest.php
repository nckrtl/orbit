<?php

declare(strict_types=1);

use App\Actions\Doctor\InstanceDoctorProbe;
use App\Actions\Doctor\ProjectDoctorProbe;
use App\Actions\Instances\Dependencies\CollectInstanceDependencyFilesAction;
use App\Actions\Instances\Dependencies\UpdateComposerDependenciesAction;
use App\Actions\Instances\DeployInstanceAction;
use App\Actions\Instances\RemoveInstanceAction;
use App\Domain\Doctor\DoctorNodeContext;
use App\Domain\Doctor\InstanceStateInspector;
use App\Domain\Doctor\NodeInspectionData;
use App\Domain\Doctor\ProjectStateInspector;
use App\Domain\Instances\Environment\InstanceEnvironmentContextResolver;
use App\Domain\Instances\InstanceSandboxGuard;
use App\Domain\Instances\InstanceState;
use App\Domain\Logs\LogStreamTargetResolver;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Projects\LifecyclePhase;
use App\Domain\Projects\ProjectLifecycleRunner;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskCompute;
use App\Infrastructure\Instances\NativeDevelopmentInstanceProvisioner;
use App\Infrastructure\Instances\RemoteDevelopmentInstanceSourceLifecycle;
use App\Infrastructure\Instances\RemoteInstanceLogReader;
use App\Infrastructure\Projects\RemoteProjectUpdateSourceMutator;
use App\Infrastructure\Ssh\SshExecutor;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskSandbox;

use function Pest\Laravel\mock;

function host_boundary_workspace(bool $associated): Instance
{
    $host = Node::query()->create(['name' => 'compute', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.20', 'public_ssh_host' => '192.0.2.20']);
    $project = Project::query()->create(['name' => 'Orbit', 'slug' => 'orbit', 'repository_url' => 'https://github.com/acme/orbit.git']);
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Boundary', 'brief' => 'Work', 'status' => 'todo', 'task_compute' => TaskCompute::Vm]);
    $sandbox = TaskSandbox::query()->create(['id' => 'b336b38c-f87c-4406-a13c-82563a1ced57', 'group_id' => $group->id, 'provider' => 'incus', 'name' => 'ot-proof', 'state' => 'running', 'desired_power' => 'running', 'spec' => ['host_id' => $host->id]]);
    $workspace = Instance::query()->create(['project_id' => $project->id, 'node_id' => $host->id, 'name' => 'task-'.$group->id,
        'checkout_path' => '/home/orbit/orbit', 'status' => InstanceState::Active, 'task_sandbox_id' => $associated ? $sandbox->id : null]);
    $group->update(['taskable_type' => $workspace->getMorphClass(), 'taskable_id' => $workspace->id]);

    return $workspace;
}

it('refuses generic operations before SSH or workspace mutation', function (string $operation, bool $associated): void {
    $workspace = host_boundary_workspace($associated);
    $before = $workspace->fresh()->getAttributes();
    mock(SshExecutor::class)->shouldReceive('execute')->never();
    $call = fn () => match ($operation) {
        'source' => app(RemoteDevelopmentInstanceSourceLifecycle::class)->prepare($workspace, true),
        'runtime' => app(NativeDevelopmentInstanceProvisioner::class)->reserve($workspace, null),
        'remove' => app(RemoveInstanceAction::class)->execute($workspace, true),
        'deploy' => app(DeployInstanceAction::class)->execute($workspace),
        'logs' => app(RemoteInstanceLogReader::class)->tail($workspace, 20),
        'stream logs' => app(LogStreamTargetResolver::class)->forInstance($workspace),
        'dependencies' => app(CollectInstanceDependencyFilesAction::class)->execute($workspace),
        'dependency inspect' => app(UpdateComposerDependenciesAction::class)->inspect($workspace),
        'setup' => app(ProjectLifecycleRunner::class)->run($workspace, LifecyclePhase::Setup),
        'environment' => app(InstanceEnvironmentContextResolver::class)->resolve($workspace, true),
        'process' => app(ProcessTargetResolver::class)->forPreparation($workspace),
        'project repository' => app(RemoteProjectUpdateSourceMutator::class)->preflightRepository([$workspace], 'old', 'new'),
    };

    try {
        $call();
        $this->fail('Sandbox operation reached the host path.');
    } catch (ResourceOperationException $exception) {
        expect($exception->errorCode)->toBe('instance.sandbox_managed')->and($exception->status)->toBe(409);
    }
    expect($workspace->fresh()->getAttributes())->toBe($before);
})->with(['source', 'runtime', 'remove', 'deploy', 'logs', 'stream logs', 'dependencies', 'dependency inspect', 'setup', 'environment', 'process', 'project repository'])->with([true, false]);

it('leaves sandbox workspaces out of physical host drift reports', function (bool $associated): void {
    $workspace = host_boundary_workspace($associated);
    mock(InstanceStateInspector::class)->shouldReceive('inspect')->never();
    mock(ProjectStateInspector::class)->shouldReceive('inspect')->never();
    $context = new DoctorNodeContext($workspace->node, new NodeInspectionData(true, 'linux', 'x86_64', true));

    $instances = app(InstanceDoctorProbe::class)->inspect($context);
    $projects = app(ProjectDoctorProbe::class)->inspect($context);

    expect($instances->checked)->toBe(0)->and($instances->issues)->toBeEmpty();
    expect($projects->checked)->toBe(0)->and($projects->issues)->toBeEmpty();
})->with([true, false]);

it('permits ordinary host workspaces', function (): void {
    $workspace = host_boundary_workspace(false);
    Task::topLevel()->where('taskable_id', $workspace->id)->update(['task_compute' => TaskCompute::Shared]);

    InstanceSandboxGuard::assertHostOperation($workspace);

    expect(InstanceSandboxGuard::isSandbox($workspace))->toBeFalse();
});
