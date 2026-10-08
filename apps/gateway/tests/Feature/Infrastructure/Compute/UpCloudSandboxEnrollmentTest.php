<?php

declare(strict_types=1);

use App\Actions\Compute\EnrollUpCloudSandboxAction;
use App\Actions\Nodes\ProvisionNodeAction;
use App\Data\Nodes\ProvisionNodeData;
use App\Domain\Compute\ComputeDriver;
use App\Domain\Compute\ComputeException;
use App\Domain\Compute\SandboxNetworkPolicy;
use App\Domain\Compute\SandboxNodeBootstrap;
use App\Domain\Compute\SandboxSpec;
use App\Domain\Compute\SandboxState;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskCompute;
use App\Infrastructure\Compute\SandboxFleetIdentity;
use App\Infrastructure\Compute\SandboxHubNetwork;
use App\Infrastructure\Compute\UpCloudSandboxNodeBootstrap;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\HostKeyScanner;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskSandbox;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

use function Pest\Laravel\mock;

function fleet_sandbox(): TaskSandbox
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
    $project = Project::query()->create(['name' => 'DLF', 'slug' => 'dlf', 'repository_url' => 'https://github.com/acme/dlf.git']);
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Work', 'brief' => 'Work', 'status' => 'todo', 'task_compute' => TaskCompute::Vm]);
    $id = (string) Str::uuid();

    return TaskSandbox::query()->create(['id' => $id, 'group_id' => $group->id, 'name' => 'orbit-sandbox-'.$id,
        'provider' => 'upcloud', 'state' => SandboxState::Running, 'desired_power' => 'running',
        'server_id' => (string) Str::uuid(), 'disk_id' => (string) Str::uuid(), 'public_address' => '93.184.216.38',
        'spec' => (new SandboxSpec('nl-ams1', '93.184.216.34', '93.184.216.35', 51820, 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFakePublicMaterial'))->toArray()]);
}

function fleet_driver(): void
{
    $driver = mock(ComputeDriver::class);
    $driver->shouldReceive('observe')->andReturnUsing(fn (TaskSandbox $s): TaskSandbox => $s);
    $driver->shouldReceive('sealNetwork')->andReturnUsing(function (TaskSandbox $s): TaskSandbox {
        $s->update(['network_policy' => 'sealed']);

        return $s;
    });
}

describe('owned UpCloud fleet enrollment', function (): void {
    it('reserves both ownership records before a remote call and retries without a second Node', function (): void {
        $s = fleet_sandbox();
        fleet_driver();
        $steps = [];
        $bootstrap = mock(SandboxNodeBootstrap::class);
        $bootstrap->shouldReceive('prepare')->once()->andReturnUsing(function (TaskSandbox $sandbox, Node $node) use (&$steps): void {
            expect($sandbox->fresh()->node_id)->toBe($node->id)->and($node->fresh()->compute_sandbox_id)->toBe($sandbox->id);
            $steps[] = 'bootstrap';
        });
        mock(SandboxNetworkPolicy::class)->shouldReceive('ensure')->twice()->andReturnUsing(function (TaskSandbox $sandbox) use (&$steps): void {
            expect($sandbox->network_policy)->toBe('sealed');
            $steps[] = 'network';
        });
        $bootstrap->shouldReceive('enroll')->once()->andReturnUsing(function (TaskSandbox $sandbox, Node $node) use (&$steps): Node {
            expect($steps)->toBe(['bootstrap', 'network']);
            $node->update(['status' => 'active']);
            $node->roles()->create(['role' => 'app-dev', 'status' => 'active']);

            return $node;
        });
        $action = app(EnrollUpCloudSandboxAction::class);
        $node = $action->execute($s);
        expect($s->fresh()->enrolled_at)->not->toBeNull()->and($node->user)->toBe('orbit');
        expect($action->execute($s)->id)->toBe($node->id)->and(Node::query()->count())->toBe(5);
    });

    it('retains ownership and sanitized failure when hub installation fails before peer publication', function (): void {
        $s = fleet_sandbox();
        fleet_driver();
        $boot = mock(SandboxNodeBootstrap::class);
        $boot->shouldReceive('prepare')->once();
        $boot->shouldNotReceive('enroll');
        mock(SandboxNetworkPolicy::class)->shouldReceive('ensure')->once()->andThrow(new RuntimeException('private downstream output'));
        expect(fn () => app(EnrollUpCloudSandboxAction::class)->execute($s))->toThrow(ComputeException::class, 'ownership is retained');
        $s->refresh();
        expect($s->node_id)->not->toBeNull()->and($s->enrolled_at)->toBeNull()->and($s->error_code)->toBe('compute.enrollment_failed');
        expect(Node::query()->findOrFail($s->node_id)->wireguard_public_key)->toBeNull();
        expect(app(SandboxFleetIdentity::class)->reserve($s)->id)->toBe($s->node_id)->and(Node::query()->count())->toBe(5);
    });

    it('never adopts an existing Node with the sandbox name', function (): void {
        $s = fleet_sandbox();
        Node::query()->create(['name' => $s->name, 'status' => 'active', 'user' => 'orbit', 'platform' => 'linux', 'public_ssh_host' => $s->public_address]);
        expect(fn () => app(SandboxFleetIdentity::class)->reserve($s))->toThrow(ComputeException::class);
        expect($s->fresh()->node_id)->toBeNull()->and(Node::query()->whereNotNull('compute_sandbox_id')->count())->toBe(0);
    });

    it('refuses changed fleet identity and grants to another Node', function (string $change): void {
        $s = fleet_sandbox();
        $identity = app(SandboxFleetIdentity::class);
        $node = $identity->reserve($s);
        match ($change) {
            'public' => $s->update(['public_address' => '93.184.216.39']),
            'node-name' => $node->update(['name' => 'foreign']),
            'grant' => $node->accessibleNodes()->attach(Node::query()->where('name', 'router')->firstOrFail()->id),
            'hub-address' => Node::query()->where('name', 'hub')->update(['wireguard_ip' => '10.44.0.8']),
        };
        expect(fn () => $identity->reserve($s))->toThrow(ComputeException::class);
        expect(Node::query()->where('compute_sandbox_id', $s->id)->count())->toBe(1);
    })->with(['public', 'node-name', 'grant', 'hub-address']);

    it('uses the recorded model endpoint when configuration changes for future reservations', function (): void {
        $s = fleet_sandbox();
        $identity = app(SandboxFleetIdentity::class);
        $node = $identity->reserve($s);
        config(['compute.upcloud.model_port' => 9999, 'compute.upcloud.dev_cluster_id' => 999]);
        expect($identity->reserve($s)->id)->toBe($node->id)->and($s->fresh()->enrollment['model_port'])->toBe(8317);
    });

    it('refuses a deleted reserved Node instead of replacing it silently', function (): void {
        $s = fleet_sandbox();
        app(SandboxFleetIdentity::class)->reserve($s)->delete();
        expect(fn () => app(SandboxFleetIdentity::class)->reserve($s))->toThrow(ComputeException::class);
        expect($s->fresh()->enrollment)->not->toBeNull()->and(Node::query()->where('compute_sandbox_id', $s->id)->count())->toBe(0);
    });

    it('keeps enrollment disabled by default and rejects ended groups without remote work', function (): void {
        $s = fleet_sandbox();
        config(['compute.upcloud.enrollment_enabled' => false]);
        expect(fn () => app(EnrollUpCloudSandboxAction::class)->execute($s))->toThrow(ComputeException::class, 'disabled');
        config(['compute.upcloud.enrollment_enabled' => true]);
        $s->group->update(['status' => 'cancelled']);
        expect(fn () => app(EnrollUpCloudSandboxAction::class)->execute($s))->toThrow(ComputeException::class);
        expect(Node::query()->whereNotNull('compute_sandbox_id')->count())->toBe(0);
    });

    it('refuses generic Node provisioning for an owned sandbox', function (): void {
        $s = fleet_sandbox();
        $node = app(SandboxFleetIdentity::class)->reserve($s);
        expect(fn () => app(ProvisionNodeAction::class)->execute(new ProvisionNodeData($node->name, $node->public_ssh_host)))
            ->toThrow(ResourceOperationException::class, 'reservation');
    });

    it('blocks rollback while enrollment ownership is outstanding', function (): void {
        $s = fleet_sandbox();
        app(SandboxFleetIdentity::class)->reserve($s);
        $migration = require database_path('migrations/2026_10_07_064540_add_fleet_enrollment_to_task_sandboxes.php');
        expect(fn () => $migration->down())->toThrow(RuntimeException::class, 'ownership');
    });
});

describe('sandbox SSH bootstrap identity', function (): void {
    it('pins the first key once and retains it across a not-ready cloud-init retry', function (): void {
        $s = fleet_sandbox();
        $node = app(SandboxFleetIdentity::class)->reserve($s);
        mock(HostKeyScanner::class)->shouldReceive('scan')->once()->with($s->public_address, 22)
            ->andReturn(new HostKey('ssh-ed25519', 'AAAAC3NzaC1lZDI1NTE5AAAAIFakePublicMaterial', 'SHA256:recorded'));
        $hosts = mock(KnownHostsStore::class);
        $hosts->shouldReceive('put')->twice();
        $hosts->shouldReceive('path')->andReturn('/known-hosts');
        mock(SshKeyProvider::class)->shouldReceive('privateKeyPath')->andReturn('/private-key');
        mock(SshExecutor::class)->shouldReceive('execute')->twice()->withArgs(fn (SshConnection $c, RemoteCommand $r): bool => $c->host === $s->public_address
            && $c->user === 'orbit' && ! $c->shareConnection && $r->timeout === 25.0)
            ->andReturn(new CommandResult(1, 'private guest output', '', 1, false), new CommandResult(0, '{"ready":true}', '', 1, false));
        $bootstrap = app(UpCloudSandboxNodeBootstrap::class);
        expect(fn () => $bootstrap->prepare($s, $node))->toThrow(ComputeException::class, 'not ready');
        expect($node->fresh()->ssh_host_fingerprint)->toBe('SHA256:recorded')->and($s->fresh()->enrollment['ssh_fingerprint'])->toBe('SHA256:recorded');
        $bootstrap->prepare($s, $node);
    });
});

it('preserves existing Node data and triggers through an enrollment schema rollback and upgrade', function (): void {
    $sandbox = fleet_sandbox();
    $before = DB::table('sqlite_master')->where('type', 'trigger')->pluck('sql', 'name')->all();
    $migration = require database_path('migrations/2026_10_07_064540_add_fleet_enrollment_to_task_sandboxes.php');
    $migration->down();
    expect(Node::query()->count())->toBe(4);
    $migration->up();
    expect(DB::table('sqlite_master')->where('type', 'trigger')->pluck('sql', 'name')->all())->toBe($before);
    $node = app(SandboxFleetIdentity::class)->reserve($sandbox);
    expect(fn () => $sandbox->delete())->toThrow(QueryException::class);
    expect($node->fresh()->compute_sandbox_id)->toBe($sandbox->id);
});

it('refuses native provisioning before hub and provider restrictions are confirmed', function (): void {
    $sandbox = fleet_sandbox();
    $node = app(SandboxFleetIdentity::class)->reserve($sandbox);
    $data = new ProvisionNodeData(name: $node->name, publicSshHost: $node->public_ssh_host,
        roles: [RoleName::AppDev], user: 'orbit', orbitUser: 'orbit', wireguardIp: $node->wireguard_ip,
        architecture: 'x86_64', clusterId: $node->cluster_id, clusterProvided: true);
    expect(fn () => app(ProvisionNodeAction::class)->executeSandbox($data, $sandbox))
        ->toThrow(ResourceOperationException::class, 'intent changed');
    expect($node->fresh()->wireguard_public_key)->toBeNull()->and($node->roles()->count())->toBe(0);
});

it('refuses SSH identity drift before contacting the guest', function (): void {
    $sandbox = fleet_sandbox();
    $node = app(SandboxFleetIdentity::class)->reserve($sandbox);
    $sandbox->enrollment = [...$sandbox->enrollment, 'ssh_fingerprint' => 'SHA256:original'];
    $sandbox->save();
    $node->update(['ssh_host_fingerprint' => 'SHA256:changed']);
    mock(HostKeyScanner::class)->shouldNotReceive('scan');
    mock(SshExecutor::class)->shouldNotReceive('execute');
    expect(fn () => app(UpCloudSandboxNodeBootstrap::class)->prepare($sandbox, $node))->toThrow(ComputeException::class, 'identity changed');
});

it('uses public SSH after interrupted peer preparation until a fleet role exists', function (): void {
    $sandbox = fleet_sandbox();
    $node = app(SandboxFleetIdentity::class)->reserve($sandbox);
    $sandbox->enrollment = [...$sandbox->enrollment, 'ssh_fingerprint' => 'SHA256:original'];
    $sandbox->save();
    $node->update(['wireguard_public_key' => 'prepared-key', 'ssh_host_fingerprint' => 'SHA256:original',
        'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'pinned-key']);
    mock(HostKeyScanner::class)->shouldNotReceive('scan');
    $hosts = mock(KnownHostsStore::class);
    $hosts->shouldReceive('put')->once()->withArgs(fn (string $host): bool => $host === $sandbox->public_address);
    $hosts->shouldReceive('path')->andReturn('/known-hosts');
    mock(SshKeyProvider::class)->shouldReceive('privateKeyPath')->andReturn('/private-key');
    mock(SshExecutor::class)->shouldReceive('execute')->once()
        ->withArgs(fn (SshConnection $connection): bool => $connection->host === $sandbox->public_address)
        ->andReturn(new CommandResult(0, '{"ready":true}', '', 1, false));
    app(UpCloudSandboxNodeBootstrap::class)->prepare($sandbox, $node);
});

it('confirms the exact hub receipt and keeps attached policies during cleanup', function (bool $valid): void {
    $sandbox = fleet_sandbox();
    app(SandboxFleetIdentity::class)->reserve($sandbox);
    $hosts = mock(KnownHostsStore::class);
    $hosts->shouldReceive('path')->andReturn('/known-hosts');
    mock(SshKeyProvider::class)->shouldReceive('privateKeyPath')->andReturn('/private-key');
    $executor = mock(SshExecutor::class);
    $receipt = json_encode(['sandbox_id' => $sandbox->id, 'operation' => 'ensure', 'confirmed' => $valid], JSON_THROW_ON_ERROR);
    $executor->shouldReceive('execute')->once()->withArgs(function (SshConnection $connection, RemoteCommand $command) use ($sandbox): bool {
        expect($connection->host)->toBe($sandbox->enrollment['hub_address']);
        expect($command->arguments[0])->toBe('sudo')->and($command->protectedInput)->not->toBeNull();

        return true;
    })->andReturn(new CommandResult(0, $receipt, '', 1, false));
    $network = app(SandboxHubNetwork::class);
    if ($valid) {
        $network->ensure($sandbox);
        expect($sandbox->fresh()->enrollment)->toHaveKey('hub_confirmed_at');
    } else {
        expect(fn () => $network->ensure($sandbox))->toThrow(ComputeException::class);
        expect($sandbox->fresh()->enrollment)->not->toHaveKey('hub_confirmed_at');
    }
    expect(fn () => $network->remove($sandbox))->toThrow(ComputeException::class, 'peer');
})->with([true, false]);

it('runs hub input validation and policy ownership regressions', function (): void {
    $process = new Process(['python3', base_path('tests/Fixtures/Compute/sandbox_hub_network_test.py'), resource_path('compute/sandbox-hub-network.py')]);
    $process->mustRun();
    expect($process->getExitCode())->toBe(0);
});
