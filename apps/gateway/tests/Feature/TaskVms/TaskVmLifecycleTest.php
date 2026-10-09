<?php

declare(strict_types=1);

use App\Actions\TaskVms\AllocateTaskVmAction;
use App\Actions\TaskVms\DestroyEndedTaskVmsAction;
use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Clusters\ClusterState;
use App\Domain\Metrics\ExporterDegradationReason;
use App\Domain\Metrics\MetricsAccessRevoker;
use App\Domain\Metrics\MetricsFleetReconciler;
use App\Domain\Nodes\NodeAgentRuntime;
use App\Domain\Nodes\NodeConverger;
use App\Domain\Nodes\NodeObservation;
use App\Domain\Nodes\NodeProvisioningIdentity;
use App\Domain\Nodes\NodeReachabilityProbe;
use App\Domain\Nodes\RoleBaselineConverger;
use App\Domain\Nodes\RoleName;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\ProxyCli\ProxyCliState;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskCapacityException;
use App\Domain\Tasks\TaskCompute;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmProvider;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmState;
use App\Domain\TaskVms\VmObservation;
use App\Domain\Tools\ToolManagerMaterializer;
use App\Infrastructure\Metrics\ServiceMetricsRuntime;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\TaskVms\TaskVmWorkspace;
use App\Jobs\TaskVms\DestroyTaskVm;
use App\Jobs\TaskVms\EnrollTaskVm;
use App\Jobs\TaskVms\PrepareTaskVmRuntime;
use App\Jobs\TaskVms\ProvisionTaskVm;
use App\Models\Cluster;
use App\Models\Instance;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\Support\FakeNodeAgentRuntime;
use Tests\Support\FakeToolManagerMaterializer;

use function Pest\Laravel\mock;

const TVM_LIFE_ORIGIN = 'http://10.44.0.3:8317';
const TVM_LIFE_FINGERPRINT = 'SHA256:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA';

/** A provider that records its calls and answers from scripted observations. */
final class TaskVmLifecycleProvider implements TaskVmProvider
{
    /** @var list<string> */
    public array $calls = [];

    /** @var list<VmObservation|null> */
    public array $observations = [];

    public bool|Throwable $ready = true;

    public ?string $userData = null;

    public ?Closure $onDestroy = null;

    public function create(TaskVm $vm, string $userData): void
    {
        $this->calls[] = 'create';
        $this->userData = $userData;
    }

    public function observe(TaskVm $vm): ?VmObservation
    {
        $this->calls[] = 'observe';

        return $this->observations === [] ? new VmObservation(true, '10.251.77.20') : array_shift($this->observations);
    }

    public function bootstrapReady(TaskVm $vm): bool
    {
        $this->calls[] = 'bootstrapReady';
        if ($this->ready instanceof Throwable) {
            throw $this->ready;
        }

        return $this->ready;
    }

    public function sshHostFingerprint(TaskVm $vm): string
    {
        $this->calls[] = 'fingerprint';

        return TVM_LIFE_FINGERPRINT;
    }

    public function destroy(TaskVm $vm): void
    {
        $this->calls[] = 'destroy';
        if ($this->onDestroy !== null) {
            ($this->onDestroy)($vm);
        }
    }
}

function tvm_life_node(string $name, string $address, string $user = 'orbit', ?RoleName $role = null): Node
{
    $node = Node::query()->create([
        'name' => $name, 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => $address, 'wireguard_ip' => $address, 'user' => $user, 'ssh_host_fingerprint' => 'SHA256:managed',
    ]);
    if ($role instanceof RoleName) {
        NodeRole::query()->create(['node_id' => $node->id, 'role' => $role, 'status' => LifecycleStatus::Active]);
    }

    return $node;
}

function tvm_life_settings(bool $enabled = true, int $maxVms = 2, ?int $clusterId = null): void
{
    app()->instance(TaskVmSettings::class, new TaskVmSettings(
        enabled: $enabled, devClusterId: $clusterId, wireguardRange: '10.44.0.128/25',
        hosts: [new TaskVmHost(test()->host->id, 'orbit-tasks', 'orbittask0', '10.251.77.0/24', 'ubuntu-26.04-vm', $maxVms, 2, '4GiB', '20GiB')],
        modelProxyOrigin: TVM_LIFE_ORIGIN, piArtifactPath: null, piArtifactSha256: null, piModels: [],
    ));
}

function tvm_life_group(TaskGroupStatus $status = TaskGroupStatus::Reserved): Task
{
    return Task::topLevel()->create([
        'project_id' => test()->project->id, 'title' => 'Export', 'brief' => 'Work', 'status' => $status,
        'task_compute' => TaskCompute::Vm, 'implementer_agent_driver' => 'pi', 'reviewer_agent_driver' => 'pi',
    ]);
}

function tvm_life_vm(Task $group, TaskVmState $state = TaskVmState::Provisioning, ?Node $node = null, string $wireguardIp = '10.44.0.129'): TaskVm
{
    $vm = TaskVm::query()->create([
        'group_id' => $group->id, 'host_node_id' => test()->host->id, 'node_id' => $node?->id, 'provider' => 'incus',
        'name' => 'tvm-pending', 'state' => $state, 'wireguard_ip' => $node->wireguard_ip ?? $wireguardIp,
        'pi_token' => str_repeat('p', 64),
    ]);
    $vm->update(['name' => 'tvm-'.$vm->id]);

    return $vm;
}

beforeEach(function (): void {
    $this->project = Project::query()->create(['name' => 'DLF', 'slug' => 'dlf', 'repository_url' => 'https://github.com/acme/dlf.git', 'default_branch' => 'main']);
    $this->host = tvm_life_node('beast', '10.44.0.7', 'nckrtl');
    $this->provider = new TaskVmLifecycleProvider;
    app()->instance(TaskVmProvider::class, $this->provider);
    tvm_life_settings();
});

describe('allocation', function (): void {
    it('records a provisioning row named after its id and queues its creation once', function (): void {
        Queue::fake();
        $group = tvm_life_group();

        $vm = app(AllocateTaskVmAction::class)->execute($group);
        $again = app(AllocateTaskVmAction::class)->execute($group);

        expect($again->id)->toBe($vm->id)
            ->and($vm->name)->toBe('tvm-'.$vm->id)
            ->and($vm->state)->toBe(TaskVmState::Provisioning)
            ->and($vm->host_node_id)->toBe($this->host->id)
            ->and($vm->wireguard_ip)->toBe('10.44.0.129')
            ->and($vm->pi_token)->toMatch('/\A[a-f0-9]{64}\z/');
        Queue::assertPushedOn('task-vms', ProvisionTaskVm::class, fn (ProvisionTaskVm $job): bool => $job->taskVmId === $vm->id && $job->connection === 'task-vms');
        Queue::assertPushed(ProvisionTaskVm::class, 1);
    });

    it('gives each live task VM its own address and waits when every host is full', function (): void {
        Queue::fake();
        $first = app(AllocateTaskVmAction::class)->execute(tvm_life_group());
        $second = app(AllocateTaskVmAction::class)->execute(tvm_life_group());

        expect($second->wireguard_ip)->toBe('10.44.0.130')
            ->and(fn () => app(AllocateTaskVmAction::class)->execute(tvm_life_group()))
            ->toThrow(TaskCapacityException::class, 'Task VM: no host has room for another task VM.');

        $first->update(['state' => TaskVmState::Destroyed]);
        expect(app(AllocateTaskVmAction::class)->execute(tvm_life_group())->wireguard_ip)->toBe('10.44.0.129');
    });

    it('skips an inactive host', function (): void {
        Queue::fake();
        $this->host->update(['status' => LifecycleStatus::Failed]);

        expect(fn () => app(AllocateTaskVmAction::class)->execute(tvm_life_group()))->toThrow(TaskCapacityException::class)
            ->and(TaskVm::query()->count())->toBe(0);
    });
});

describe('claim', function (): void {
    it('waits without a task VM while task VMs are off', function (): void {
        tvm_life_settings(enabled: false);

        expect(fn () => app(TaskVmWorkspace::class)->node(tvm_life_group()))
            ->toThrow(TaskCapacityException::class, 'Task VM: task VMs are not enabled on this Gateway.')
            ->and(TaskVm::query()->count())->toBe(0);
    });

    it('allocates on the first claim and waits until the task VM is ready', function (): void {
        Queue::fake();
        $group = tvm_life_group();

        expect(fn () => app(TaskVmWorkspace::class)->node($group))->toThrow(TaskCapacityException::class, 'Task VM: provisioning.');

        $vm = TaskVm::query()->sole();
        $vm->update(['state' => TaskVmState::Failed, 'error_code' => 'task_vm.bootstrap_failed', 'error_message' => 'Cloud-init failed.']);
        expect(fn () => app(TaskVmWorkspace::class)->node($group))
            ->toThrow(TaskCapacityException::class, 'Task VM: failed: task_vm.bootstrap_failed: Cloud-init failed.');

        $node = tvm_life_node('tvm-'.$vm->id, '10.44.0.129', role: RoleName::AppDev);
        $vm->update(['state' => TaskVmState::Ready, 'node_id' => $node->id]);
        expect(app(TaskVmWorkspace::class)->node($group)->id)->toBe($node->id);
    });
});

describe(ProvisionTaskVm::class, function (): void {
    it('launches the VM with the Gateway key in its user-data, then queues enrollment', function (): void {
        Queue::fake();
        mock(SshKeyProvider::class)->shouldReceive('publicKey')->andReturn('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGatewayKey gateway');
        $vm = tvm_life_vm(tvm_life_group());

        app()->call([new ProvisionTaskVm($vm->id), 'handle']);

        expect($this->provider->calls)->toBe(['create'])
            ->and($this->provider->userData)->toStartWith("#cloud-config\n")->toContain('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGatewayKey gateway');
        Queue::assertPushedOn('task-vms', EnrollTaskVm::class, fn (EnrollTaskVm $job): bool => $job->taskVmId === $vm->id);
    });

    it('never creates an enrolled or settled VM again', function (TaskVmState $state, bool $enrolled): void {
        Queue::fake();
        $group = tvm_life_group();
        $vm = tvm_life_vm($group, $state, $enrolled ? tvm_life_node('tvm-node', '10.44.0.140', role: RoleName::AppDev) : null);

        app()->call([new ProvisionTaskVm($vm->id), 'handle']);

        expect($this->provider->calls)->toBe([]);
        Queue::assertNothingPushed();
    })->with([
        'enrolled' => [TaskVmState::Provisioning, true],
        'failed' => [TaskVmState::Failed, false],
        'destroying' => [TaskVmState::Destroying, false],
    ]);

    it('marks only a provisioning row failed with the error code', function (): void {
        $vm = tvm_life_vm(tvm_life_group());
        $destroying = tvm_life_vm(tvm_life_group(), TaskVmState::Destroying, wireguardIp: '10.44.0.131');
        $failure = new TaskVmException('task_vm.host_command_failed', '`incus launch` failed.', 502);

        (new ProvisionTaskVm($vm->id))->failed($failure);
        (new ProvisionTaskVm($destroying->id))->failed($failure);
        (new EnrollTaskVm($vm->id))->failed(new RuntimeException('boom'));

        expect($vm->fresh()?->state)->toBe(TaskVmState::Failed)
            ->and($vm->fresh()?->error_code)->toBe('task_vm.host_command_failed')
            ->and($vm->fresh()?->error_message)->toBe('`incus launch` failed.')
            ->and($destroying->fresh()?->state)->toBe(TaskVmState::Destroying)
            ->and($destroying->fresh()?->error_code)->toBeNull();
    });

    it('retries a failed create once and is unique for its task VM', function (): void {
        $job = new ProvisionTaskVm(7);

        expect($job->tries)->toBe(2)->and($job->uniqueId())->toBe('7')->and($job->queue)->toBe('task-vms');
    });
});

describe(EnrollTaskVm::class, function (): void {
    beforeEach(function (): void {
        Queue::fake();
        Sleep::fake();
        $this->converged = [];
        app()->instance(NodeConverger::class, new class($this->converged) implements NodeConverger
        {
            /** @param list<array<string, mixed>> $converged */
            public function __construct(private array &$converged) {}

            public function converge(Node $node, NodeProvisioningIdentity $identity, ?string $expectedSshHostFingerprint = null, bool $rolelessOperator = false): NodeObservation
            {
                $this->converged[] = [
                    'host' => $node->public_ssh_host, 'jump' => $node->ssh_jump_node_id, 'wireguard_ip' => $node->wireguard_ip,
                    'user' => $identity->bootstrapUser, 'fingerprint' => $expectedSshHostFingerprint,
                    'linked' => TaskVm::query()->where('node_id', $node->id)->exists(),
                ];

                return new NodeObservation('x86_64');
            }
        });
        app()->instance(RoleBaselineConverger::class, new class implements RoleBaselineConverger
        {
            public function converge(Node $node, NodeRole $assignment): void {}

            public function remove(Node $node, NodeRole $assignment, bool $purgeData): void {}

            public function removeUnreachable(Node $node, NodeRole $assignment): void {}
        });
        app()->instance(ToolManagerMaterializer::class, new FakeToolManagerMaterializer);
        app()->instance(NodeAgentRuntime::class, new FakeNodeAgentRuntime);
        app()->instance(MetricsFleetReconciler::class, Mockery::mock(MetricsFleetReconciler::class)->shouldReceive('reconcile')->getMock());
        $this->cluster = Cluster::query()->create(['name' => 'development', 'tld' => 'test', 'state' => ClusterState::Active]);
        tvm_life_settings(clusterId: $this->cluster->id);
    });

    it('polls while cloud-init runs, without enrolling', function (): void {
        $this->provider->ready = false;
        $vm = tvm_life_vm(tvm_life_group());
        $job = (new EnrollTaskVm($vm->id))->withFakeQueueInteractions();

        app()->call([$job, 'handle']);

        $job->assertReleased(15);
        expect($this->converged)->toBe([])->and(Node::query()->where('name', $vm->name)->exists())->toBeFalse();
    });

    it('enrolls the VM as its app-dev Node through the host, linked before any convergence', function (): void {
        $vm = tvm_life_vm(tvm_life_group());

        app()->call([new EnrollTaskVm($vm->id), 'handle']);

        $node = Node::query()->where('name', $vm->name)->sole();
        expect($this->converged)->toBe([[
            'host' => '10.251.77.20', 'jump' => $this->host->id, 'wireguard_ip' => '10.44.0.129',
            'user' => 'orbit', 'fingerprint' => TVM_LIFE_FINGERPRINT, 'linked' => true,
        ]])
            ->and($vm->fresh()?->node_id)->toBe($node->id)
            ->and($vm->fresh()?->address)->toBe('10.251.77.20')
            ->and($node->status)->toBe(LifecycleStatus::Active)
            ->and($node->user)->toBe('orbit')
            ->and($node->cluster_id)->toBe($this->cluster->id)
            ->and($node->roles()->pluck('role')->all())->toBe([RoleName::AppDev])
            ->and($this->provider->calls)->toBe(['observe', 'bootstrapReady', 'fingerprint']);
        Queue::assertPushedOn('task-vms', PrepareTaskVmRuntime::class, fn (PrepareTaskVmRuntime $job): bool => $job->taskVmId === $vm->id);
    });

    it('tolerates one stopped reading during a guest reboot', function (): void {
        $this->provider->observations = [new VmObservation(false, null), new VmObservation(true, '10.251.77.20')];
        $vm = tvm_life_vm(tvm_life_group());

        app()->call([new EnrollTaskVm($vm->id), 'handle']);

        Sleep::assertSleptTimes(1);
        expect($vm->fresh()?->node_id)->not->toBeNull();
    });

    it('fails at once for a missing or stopped VM, a cloud-init error, or a slow boot', function (Closure $arrange, string $code): void {
        $vm = tvm_life_vm(tvm_life_group());
        $arrange($this->provider, $vm);
        $job = (new EnrollTaskVm($vm->id))->withFakeQueueInteractions();

        app()->call([$job, 'handle']);

        $job->assertFailedWith(fn (TaskVmException $exception): bool => $exception->errorCode === $code);
        expect($this->converged)->toBe([]);
    })->with([
        'absent' => [fn (TaskVmLifecycleProvider $provider) => $provider->observations = [null], 'task_vm.vm_not_running'],
        'stopped' => [fn (TaskVmLifecycleProvider $provider) => $provider->observations = [new VmObservation(false, null), new VmObservation(false, null)], 'task_vm.vm_not_running'],
        'cloud-init error' => [fn (TaskVmLifecycleProvider $provider) => $provider->ready = new TaskVmException('task_vm.bootstrap_failed', 'Cloud-init failed.', 502), 'task_vm.bootstrap_failed'],
        'slow boot' => [function (TaskVmLifecycleProvider $provider, TaskVm $vm): void {
            $provider->ready = false;
            $vm->forceFill(['created_at' => now()->subMinutes(11)])->save();
        }, 'task_vm.bootstrap_timeout'],
    ]);

    it('only queues the runtime for a VM whose Node is already active', function (): void {
        $vm = tvm_life_vm(tvm_life_group(), node: tvm_life_node('tvm-node', '10.44.0.129', role: RoleName::AppDev));

        app()->call([new EnrollTaskVm($vm->id), 'handle']);

        expect($this->provider->calls)->toBe([])->and($this->converged)->toBe([]);
        Queue::assertPushed(PrepareTaskVmRuntime::class);
    });
});

describe(PrepareTaskVmRuntime::class, function (): void {
    it('refuses a task VM without an active Node', function (): void {
        $vm = tvm_life_vm(tvm_life_group());

        expect(fn () => app()->call([new PrepareTaskVmRuntime($vm->id), 'handle']))
            ->toThrow(fn (TaskVmException $exception) => expect($exception->errorCode)->toBe('task_vm.not_enrolled'));
        expect($vm->fresh()?->state)->toBe(TaskVmState::Provisioning);
    });
});

describe(DestroyTaskVm::class, function (): void {
    beforeEach(function (): void {
        $this->gateway = tvm_life_node('gateway', '10.44.0.2', role: RoleName::Gateway);
        $this->events = new ArrayObject;
        $events = $this->events;
        $services = Mockery::mock(ServiceMetricsRuntime::class);
        $services->shouldReceive('snapshot')->andReturn('{}');
        $services->shouldReceive('converge');
        $services->shouldReceive('restore');
        app()->instance(ServiceMetricsRuntime::class, $services);
        app()->instance(MetricsAccessRevoker::class, new class implements MetricsAccessRevoker
        {
            public function revoke(): void {}
        });
        app()->instance(NodeReachabilityProbe::class, new class implements NodeReachabilityProbe
        {
            public function degradation(Node $node): ?ExporterDegradationReason
            {
                return ExporterDegradationReason::Unreachable;
            }
        });
        app()->instance(PrivateDnsManager::class, new class($events) implements PrivateDnsManager
        {
            public function __construct(private ArrayObject $events) {}

            public function converge(?Node $pendingNode = null): void
            {
                $this->events->append('node removal: '.TaskVm::query()->sole()->state->value);
            }
        });
        $this->provider->onDestroy = static function (TaskVm $vm) use ($events): void {
            $events->append('vm deletion: '.$vm->fresh()?->state->value);
        };
        app(ProxyCliState::class)->enable(1, 'cache', TVM_LIFE_ORIGIN, 'management-key', 'read-token', 'control-token');
        $keys = new ArrayObject([str_repeat('a', 64)]);
        Http::fake([TVM_LIFE_ORIGIN.'/*' => function (Request $request) use ($events, $keys) {
            if (parse_url($request->url(), PHP_URL_PATH) === '/v0/management/api-keys') {
                if ($request->method() === 'PATCH') {
                    $kept = array_values(array_filter($keys->getArrayCopy(), static fn (string $key): bool => $key !== $request['old']));
                    $keys->exchangeArray($request['new'] === '' ? $kept : [...$kept, $request['new']]);
                    if ($request['new'] === '') {
                        $events->append('key revoke: '.TaskVm::query()->sole()->state->value);
                    }
                }

                return Http::response(['api-keys' => $keys->getArrayCopy()]);
            }
            $token = substr($request->header('Authorization')[0] ?? '', 7);

            return Http::response([], in_array($token, $keys->getArrayCopy(), true) ? 200 : 401);
        }]);
    });

    it('revokes the key, deletes the VM and removes the Node before the row is destroyed', function (): void {
        $node = tvm_life_node('tvm-node', '10.44.0.129', role: RoleName::AppDev);
        $node->processes()->create([
            'name' => 'pi-server', 'runtime' => ProcessRuntime::Systemd, 'working_directory' => '/home/orbit',
            'runtime_config' => ['command' => ['/home/orbit/.local/bin/pi-server', 'serve']], 'restart_policy' => 'always',
            'status' => LifecycleStatus::Active,
        ]);
        $vm = tvm_life_vm(tvm_life_group(TaskGroupStatus::Completed), TaskVmState::Ready, $node);
        $vm->update(['model_key' => str_repeat('a', 64), 'model_proxy_origin' => TVM_LIFE_ORIGIN]);

        app()->call([new DestroyTaskVm($vm->id), 'handle']);

        expect(array_values(array_unique($this->events->getArrayCopy())))->toBe(['key revoke: destroying', 'vm deletion: destroying', 'node removal: destroying'])
            ->and(Node::query()->find($node->id))->toBeNull()
            ->and($vm->fresh()?->state)->toBe(TaskVmState::Destroyed)
            ->and($vm->fresh()?->destroyed_at)->not->toBeNull()
            ->and($vm->fresh()?->model_key)->toBeNull()
            ->and($vm->fresh()?->node_id)->toBeNull();
    });

    it('waits while an Instance is left on the Node', function (): void {
        $node = tvm_life_node('tvm-node', '10.44.0.129', role: RoleName::AppDev);
        $group = tvm_life_group(TaskGroupStatus::Cancelled);
        $vm = tvm_life_vm($group, TaskVmState::Ready, $node);
        Instance::query()->create(['project_id' => $this->project->id, 'node_id' => $node->id, 'name' => 'task-'.$group->id, 'checkout_path' => '/home/orbit/apps/dlf/task-'.$group->id]);

        app()->call([new DestroyTaskVm($vm->id), 'handle']);

        expect($this->provider->calls)->toBe([])->and($vm->fresh()?->state)->toBe(TaskVmState::Ready);
    });

    it('keeps the row destroying with the error when a step fails, and retries until it succeeds', function (): void {
        $vm = tvm_life_vm(tvm_life_group(TaskGroupStatus::Cancelled), TaskVmState::Failed);
        $this->provider->onDestroy = static fn () => throw new TaskVmException('task_vm.host_command_failed', '`incus delete` failed.', 502);

        expect(fn () => app()->call([new DestroyTaskVm($vm->id), 'handle']))->toThrow(TaskVmException::class);
        expect($vm->fresh()?->state)->toBe(TaskVmState::Destroying)
            ->and($vm->fresh()?->error_code)->toBe('task_vm.host_command_failed')
            ->and((new DestroyTaskVm($vm->id))->tries)->toBe(0);

        $this->provider->onDestroy = null;
        app()->call([new DestroyTaskVm($vm->id), 'handle']);
        expect($vm->fresh()?->state)->toBe(TaskVmState::Destroyed)->and($vm->fresh()?->error_code)->toBeNull();
    });
});

describe(DestroyEndedTaskVmsAction::class, function (): void {
    it('queues destruction only for ended groups whose workspace is gone and whose claim is over', function (): void {
        Queue::fake();
        $ended = tvm_life_vm(tvm_life_group(TaskGroupStatus::Completed), TaskVmState::Ready, tvm_life_node('tvm-a', '10.44.0.129'));
        $failed = tvm_life_vm(tvm_life_group(TaskGroupStatus::Cancelled), TaskVmState::Failed, wireguardIp: '10.44.0.130');
        $running = tvm_life_vm(tvm_life_group(TaskGroupStatus::Running), TaskVmState::Ready, tvm_life_node('tvm-b', '10.44.0.131'));
        $withWorkspace = tvm_life_vm($group = tvm_life_group(TaskGroupStatus::Cancelled), TaskVmState::Ready, $node = tvm_life_node('tvm-c', '10.44.0.132'));
        Instance::query()->create(['project_id' => $this->project->id, 'node_id' => $node->id, 'name' => 'task-'.$group->id, 'checkout_path' => '/home/orbit/apps/dlf/task-'.$group->id]);
        $claimed = tvm_life_vm(tvm_life_group(TaskGroupStatus::Cancelled), TaskVmState::Provisioning, wireguardIp: '10.44.0.133');
        $claimed->group->update(['reserved_at' => now()]);
        $destroyed = tvm_life_vm(tvm_life_group(TaskGroupStatus::Completed), TaskVmState::Destroyed);

        expect(app(DestroyEndedTaskVmsAction::class)->execute())->toBe(2);

        $queued = Queue::pushed(DestroyTaskVm::class)->map(fn (DestroyTaskVm $job): int => $job->taskVmId)->sort()->values()->all();
        expect($queued)->toBe([$ended->id, $failed->id])
            ->and([$running->id, $withWorkspace->id, $claimed->id, $destroyed->id])->not->toContain(...$queued);
    });
});

describe('worker', function (): void {
    it('runs from the Gateway scheduler, last and in the foreground, only while a task VM job waits', function (): void {
        Artisan::call('schedule:list');
        $events = app(Schedule::class)->events();
        $worker = collect($events)->first(fn (Event $event): bool => str_contains((string) $event->command, 'queue:work'));

        expect($worker)->not->toBeNull()
            ->and($worker->command)->toEndWith("'artisan' queue:work task-vms --queue=task-vms --stop-when-empty --max-time=50 --timeout=1500")
            ->and($worker)->toBe(end($events))
            ->and($worker->expression)->toBe('* * * * *')
            ->and($worker->withoutOverlapping)->toBeTrue()
            ->and($worker->expiresAt)->toBe(30)
            ->and($worker->runInBackground)->toBeFalse()
            ->and($worker->filtersPass(app()))->toBeFalse();

        DestroyTaskVm::dispatch(tvm_life_vm(tvm_life_group(TaskGroupStatus::Cancelled), TaskVmState::Failed)->id);

        expect($worker->filtersPass(app()))->toBeTrue()
            ->and(DB::table('jobs')->sole()->queue)->toBe('task-vms');
    });
});
