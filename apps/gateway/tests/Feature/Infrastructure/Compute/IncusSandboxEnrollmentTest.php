<?php

declare(strict_types=1);

use App\Actions\Compute\EnrollIncusSandboxAction;
use App\Actions\Nodes\ProvisionNodeAction;
use App\Data\Nodes\ProvisionNodeData;
use App\Domain\Compute\ComputeException;
use App\Domain\Compute\SandboxNetworkPolicy;
use App\Domain\Compute\SandboxState;
use App\Domain\Metrics\MetricsFleetReconciler;
use App\Domain\Nodes\NodeConverger;
use App\Domain\Nodes\NodeObservation;
use App\Domain\Nodes\NodeProvisioningIdentity;
use App\Domain\Nodes\RoleBaselineConverger;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskCompute;
use App\Domain\Tools\ToolManagerMaterializer;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\Compute\IncusSandboxNodeBootstrap;
use App\Infrastructure\Compute\SandboxFleetIdentity;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tasks\IncusSandboxHost;
use App\Models\Cluster;
use App\Models\Node;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskSandbox;
use Illuminate\Support\Str;
use Tests\Support\FakeToolManagerMaterializer;

use function Pest\Laravel\mock;

function incus_fleet_key(): HostKey
{
    $value = 'AAAAC3NzaC1lZDI1NTE5AAAAIHdUmJNAeflz28V7EadKJL3DLqnMqS6JyEQJmpCPNG5T';

    return new HostKey('ssh-ed25519', $value, 'SHA256:'.rtrim(base64_encode(hash('sha256', base64_decode($value), true)), '='));
}

function incus_fleet_sandbox(): TaskSandbox
{
    $cluster = Cluster::query()->create(['name' => 'Local dev', 'tld' => 'test', 'state' => 'active']);
    foreach ([['hub', '10.44.0.1', '93.184.216.35', RoleName::Vpn], ['gateway', '10.44.0.2', '93.184.216.34', RoleName::Gateway],
        ['router', '10.44.0.9', '93.184.216.36', RoleName::Router], ['model', '10.44.0.3', '93.184.216.37', null],
        ['compute', '10.44.0.20', '93.184.216.40', null]] as [$name, $address, $public, $role]) {
        $node = Node::query()->create(['name' => $name, 'cluster_id' => $role === RoleName::Router ? $cluster->id : null,
            'status' => 'active', 'user' => 'orbit', 'platform' => 'linux', 'architecture' => 'x86_64', 'wireguard_ip' => $address, 'public_ssh_host' => $public]);
        if ($role !== null) {
            $node->roles()->create(['role' => $role, 'cluster_id' => $role === RoleName::Router ? $cluster->id : null, 'status' => 'active']);
        }
    }
    $host = Node::query()->where('name', 'compute')->firstOrFail();
    config(['compute.incus.enrollment_enabled' => true, 'compute.incus.dev_cluster_id' => $cluster->id,
        'compute.incus.model_address' => '10.44.0.3', 'compute.incus.model_port' => 8317,
        'compute.incus.hosts' => [['node_id' => $host->id, 'project' => 'orbit-sandbox-proof-318a36c8', 'pool' => 'proof',
            'max_vms' => 9, 'blocked_networks' => ['192.168.0.0/16'], 'gateway_address' => '10.44.0.2',
            'project_bootstrap' => ['wireguard_address' => '93.184.216.35', 'wireguard_port' => 51820]]]]);
    $project = Project::query()->create(['name' => 'DLF', 'slug' => 'dlf', 'repository_url' => 'https://github.com/acme/dlf.git']);
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Local work', 'brief' => 'Work', 'status' => 'todo', 'task_compute' => TaskCompute::Vm]);
    $id = (string) Str::uuid();

    return TaskSandbox::query()->create(['id' => $id, 'group_id' => $group->id, 'name' => 'ot-'.substr(hash('sha256', $id), 0, 10),
        'provider' => 'incus', 'state' => SandboxState::Running, 'desired_power' => 'running', 'network_policy' => 'sealed',
        'spec' => ['host_id' => $host->id, 'project' => 'orbit-sandbox-proof-318a36c8', 'pool' => 'proof', 'project_slug' => 'dlf',
            'images' => ['operator' => str_repeat('a', 64)], 'subnet' => '10.233.201.0/24', 'blocked_networks' => ['192.168.0.0/16'],
            'project_bootstrap' => ['ssh_host' => '10.44.0.20', 'ssh_port' => 24201, 'gateway_address' => '10.44.0.2',
                'wireguard_address' => '93.184.216.35', 'wireguard_port' => 51820]]]);
}

function incus_fleet_transport(TaskSandbox $sandbox, array &$operations, ?string $changedKey = null, bool $bootstrapFailed = false): void
{
    $keys = mock(SshKeyProvider::class);
    $keys->shouldReceive('privateKeyPath')->andReturn('/private-key');
    $keys->shouldReceive('publicKey')->andReturn('ssh-ed25519 '.incus_fleet_key()->value);
    $hosts = mock(KnownHostsStore::class);
    $hosts->shouldReceive('path')->andReturn('/known-hosts');
    $hosts->shouldReceive('put')->withArgs(fn (string $host, int $port, HostKey $key): bool => (($host === '10.44.0.20' && $port === 24201) || (str_starts_with($host, '10.44.') && $port === 22))
        && $key->fingerprint === incus_fleet_key()->fingerprint);
    mock(SshExecutor::class)->shouldReceive('execute')->andReturnUsing(function (SshConnection $connection, RemoteCommand $command) use ($sandbox, &$operations, $changedKey, $bootstrapFailed): CommandResult {
        expect($connection->host)->toBe('10.44.0.20');
        expect($command->arguments)->toBe(['/usr/local/bin/orbit-agent', 'sandbox']);
        $input = json_decode(stream_get_contents($command->protectedInput->stream()), true, flags: JSON_THROW_ON_ERROR);
        $operations[] = $input['operation'];
        if ($input['operation'] === 'guest_command') {
            expect($input['guest']['role'])->toBe('operator');
            expect($input['guest']['argv'])->toBe(['sudo', '-n', 'python3', '-I', '-c', file_get_contents(resource_path('compute/guest-project-ssh.py'))]);
            expect(json_decode(base64_decode($input['guest']['stdin']), true))->toBe(['public_key' => 'ssh-ed25519 '.incus_fleet_key()->value]);
        }
        $result = match ($input['operation']) {
            'guest_command' => ['name' => $sandbox->name, 'role' => 'operator', 'exit_code' => $bootstrapFailed ? 1 : 0,
                'stdout' => base64_encode(json_encode(['ready' => true])), 'stderr' => base64_encode(''), 'duration_ms' => 1, 'truncated' => false, 'timed_out' => false],
            'observe' => ['name' => $sandbox->name, 'power' => 'running', 'instances' => [['name' => $sandbox->name.'-operator', 'state' => 'running']]],
            'project_identity', 'project_fleet_identity' => ['name' => $sandbox->name, 'guest' => $sandbox->name.'-operator', 'project_slug' => 'dlf',
                'image' => str_repeat('a', 64), 'pool' => 'proof', 'subnet' => '10.233.201.0/24', 'address' => '10.233.201.10',
                'ssh_key' => 'ssh-ed25519 '.($changedKey ?? incus_fleet_key()->value)],
            default => throw new RuntimeException('Unexpected host mutation'),
        };

        return new CommandResult(0, json_encode($result, JSON_THROW_ON_ERROR), '', 1, false);
    });
}

it('pins the private bootstrap endpoint and both ownership records before hub or peer publication', function (): void {
    $sandbox = incus_fleet_sandbox();
    $steps = [];
    incus_fleet_transport($sandbox, $steps);
    mock(SandboxNetworkPolicy::class)->shouldReceive('ensure')->twice()->andReturnUsing(function (TaskSandbox $s) use (&$steps): void {
        $node = Node::query()->findOrFail($s->node_id);
        expect($node->compute_sandbox_id)->toBe($s->id);
        expect($node->ssh_host_fingerprint)->toBe(incus_fleet_key()->fingerprint);
        $s->enrollment = [...$s->enrollment, 'hub_confirmed_at' => now()->toIso8601String()];
        $s->save();
        $steps[] = 'hub';
    });
    mock(NodeConverger::class)->shouldReceive('converge')->once()->andReturnUsing(function (Node $node, NodeProvisioningIdentity $identity, ?string $fingerprint) use (&$steps): NodeObservation {
        expect($steps)->toBe(['observe', 'project_identity', 'project_fleet_identity', 'guest_command', 'hub']);
        expect($node->public_ssh_host)->toBe('10.44.0.20');
        expect($node->public_ssh_port)->toBe(24201);
        expect($fingerprint)->toBe(incus_fleet_key()->fingerprint);

        return new NodeObservation('x86_64');
    });
    app()->instance(ToolManagerMaterializer::class, new FakeToolManagerMaterializer);
    mock(RoleBaselineConverger::class)->shouldReceive('converge')->once();
    mock(MetricsFleetReconciler::class)->shouldReceive('reconcile')->once();
    $action = app(EnrollIncusSandboxAction::class);

    $node = $action->execute($sandbox);
    expect($action->execute($sandbox)->id)->toBe($node->id);
    expect($sandbox->fresh()->enrolled_at)->not->toBeNull();
    expect(Node::query()->where('compute_sandbox_id', $sandbox->id)->count())->toBe(1);
    expect($node->roles()->pluck('role')->all())->toBe([RoleName::AppDev]);
    expect($node->accessibleNodes()->count())->toBe(0);
    expect($steps)->toBe(['observe', 'project_identity', 'project_fleet_identity', 'guest_command', 'hub', 'observe', 'project_fleet_identity', 'guest_command', 'hub']);
});

it('retains a pinned Node when hub policy fails without publishing a peer', function (): void {
    $sandbox = incus_fleet_sandbox();
    $steps = [];
    incus_fleet_transport($sandbox, $steps);
    mock(SandboxNetworkPolicy::class)->shouldReceive('ensure')->once()->andThrow(new RuntimeException('private downstream output'));
    mock(NodeConverger::class)->shouldNotReceive('converge');

    expect(fn () => app(EnrollIncusSandboxAction::class)->execute($sandbox))->toThrow(ComputeException::class, 'ownership is retained');
    $sandbox->refresh();
    $node = Node::query()->findOrFail($sandbox->node_id);
    expect($sandbox->error_code)->toBe('compute.enrollment_failed');
    expect($sandbox->enrolled_at)->toBeNull();
    expect($node->wireguard_public_key)->toBeNull();
    expect(app(SandboxFleetIdentity::class)->reserve($sandbox)->id)->toBe($node->id);
    expect($steps)->toBe(['observe', 'project_identity', 'project_fleet_identity', 'guest_command']);
});

it('refuses private placement drift and grants before retrying enrollment', function (string $change): void {
    $sandbox = incus_fleet_sandbox();
    $identity = app(SandboxFleetIdentity::class);
    $node = $identity->reserve($sandbox, incus_fleet_key());
    match ($change) {
        'port' => $node->update(['public_ssh_port' => 22]),
        'image' => $sandbox->update(['spec' => [...$sandbox->spec, 'images' => ['operator' => str_repeat('b', 64)]]]),
        'host' => Node::query()->where('name', 'compute')->update(['wireguard_ip' => '10.44.0.21']),
        'pool' => $sandbox->update(['spec' => [...$sandbox->spec, 'pool' => 'foreign']]),
        'key' => $node->update(['ssh_host_key' => 'changed']),
        'grant' => $node->accessibleNodes()->attach(Node::query()->where('name', 'router')->firstOrFail()->id),
        'deleted-node' => $node->delete(),
        'hub-public' => Node::query()->where('name', 'hub')->update(['public_ssh_host' => '93.184.216.41']),
        'hub-port' => app(VpnSettings::class)->configure('10.44.0.0/24', 51821),
    };
    mock(SshExecutor::class)->shouldNotReceive('execute');

    expect(fn () => $identity->reserve($sandbox))->toThrow(ComputeException::class);
    expect($sandbox->fresh()->enrollment)->not->toBeNull();
})->with(['port', 'image', 'host', 'pool', 'key', 'grant', 'deleted-node', 'hub-public', 'hub-port']);

it('refuses enrollment without opt-in or for an ended group before contacting the host', function (bool $enabled): void {
    $sandbox = incus_fleet_sandbox();
    config(['compute.incus.enrollment_enabled' => $enabled]);
    if ($enabled) {
        $sandbox->group->update(['status' => 'cancelled']);
    }
    mock(SshExecutor::class)->shouldNotReceive('execute');

    expect(fn () => app(EnrollIncusSandboxAction::class)->execute($sandbox))->toThrow(ComputeException::class);
    expect($sandbox->fresh()->node_id)->toBeNull();
})->with([false, true]);

it('does not adopt a same-name Node or reserve one without a host-attested key', function (bool $collision): void {
    $sandbox = incus_fleet_sandbox();
    if ($collision) {
        Node::query()->create(['name' => $sandbox->name, 'status' => 'active', 'user' => 'orbit', 'platform' => 'linux', 'public_ssh_host' => '10.44.0.20']);
    }

    expect(fn () => app(SandboxFleetIdentity::class)->reserve($sandbox, $collision ? incus_fleet_key() : null))->toThrow(ComputeException::class);
    expect($sandbox->fresh()->node_id)->toBeNull();
})->with([false, true]);

it('keeps initial admission separate from a reserved fleet retry', function (): void {
    $sandbox = incus_fleet_sandbox();
    $node = app(SandboxFleetIdentity::class)->reserve($sandbox, incus_fleet_key());
    $steps = [];
    incus_fleet_transport($sandbox, $steps);
    $host = Node::query()->where('name', 'compute')->firstOrFail();

    expect(fn () => app(IncusSandboxHost::class)->projectIdentity($host, $sandbox, 9))->toThrow(ResourceOperationException::class);
    app(IncusSandboxNodeBootstrap::class)->prepare($sandbox, $node);
    expect($steps)->toBe(['project_fleet_identity', 'guest_command']);
});

it('refuses SSH bootstrap failure before publishing the hub policy or peer', function (): void {
    $sandbox = incus_fleet_sandbox();
    $steps = [];
    incus_fleet_transport($sandbox, $steps, bootstrapFailed: true);
    mock(SandboxNetworkPolicy::class)->shouldNotReceive('ensure');
    mock(NodeConverger::class)->shouldNotReceive('converge');

    expect(fn () => app(EnrollIncusSandboxAction::class)->execute($sandbox))->toThrow(ComputeException::class, 'bootstrap was not confirmed');
    expect($sandbox->fresh()->error_code)->toBe('compute.bootstrap_not_ready');
    expect(Node::query()->findOrFail($sandbox->fresh()->node_id)->wireguard_public_key)->toBeNull();
});

it('refuses a changed host-attested SSH key without repinning it', function (): void {
    $sandbox = incus_fleet_sandbox();
    $node = app(SandboxFleetIdentity::class)->reserve($sandbox, incus_fleet_key());
    $steps = [];
    $other = base64_encode("\0\0\0\x0bssh-ed25519\0\0\0\x20".str_repeat('x', 32));
    incus_fleet_transport($sandbox, $steps, $other);

    expect(fn () => app(IncusSandboxNodeBootstrap::class)->prepare($sandbox, $node))->toThrow(ComputeException::class, 'identity changed');
    expect($node->fresh()->ssh_host_fingerprint)->toBe(incus_fleet_key()->fingerprint);
});

it('rejects a different native bootstrap port before publishing a peer', function (): void {
    $sandbox = incus_fleet_sandbox();
    $node = app(SandboxFleetIdentity::class)->reserve($sandbox, incus_fleet_key());
    $sandbox->enrollment = [...$sandbox->enrollment, 'hub_confirmed_at' => now()->toIso8601String()];
    $sandbox->save();
    mock(NodeConverger::class)->shouldNotReceive('converge');
    $data = new ProvisionNodeData(name: $node->name, publicSshHost: $node->public_ssh_host, roles: [RoleName::AppDev],
        publicSshPort: 22, user: 'orbit', orbitUser: 'orbit', wireguardIp: $node->wireguard_ip,
        expectedSshHostFingerprint: $node->ssh_host_fingerprint, architecture: 'x86_64', clusterId: $node->cluster_id, clusterProvided: true);

    expect(fn () => app(ProvisionNodeAction::class)->executeSandbox($data, $sandbox))->toThrow(ResourceOperationException::class, 'intent changed');
    expect($node->fresh()->wireguard_public_key)->toBeNull();
});

it('refuses an unconfirmed host policy before reserving a fleet Node', function (): void {
    $sandbox = incus_fleet_sandbox();
    $sandbox->update(['network_policy' => 'bootstrap']);
    $steps = [];
    incus_fleet_transport($sandbox, $steps);
    mock(SandboxNetworkPolicy::class)->shouldNotReceive('ensure');

    expect(fn () => app(EnrollIncusSandboxAction::class)->execute($sandbox))->toThrow(ComputeException::class, 'host policy');
    expect($sandbox->fresh()->node_id)->toBeNull();
    expect($steps)->toBe(['observe']);
});
