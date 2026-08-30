<?php

declare(strict_types=1);

use App\Domain\Metrics\ExporterPreference;
use App\Domain\Metrics\ExporterPreferenceRepository;
use App\Domain\Metrics\ExporterSelector;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Firewall\UfwRuleOwnership;
use App\Infrastructure\Metrics\MetricsExporterRuntime;
use App\Infrastructure\Metrics\MetricsExporterSshExecutor;
use App\Infrastructure\Metrics\MetricsExporterState;
use App\Infrastructure\Metrics\NativeMetricsExporterLifecycle;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;

it('returns stable targets from explicit defaults and prospective roles', function (): void {
    $metrics = metricsExporterLifecycleNode('metrics', '10.44.0.3');
    $metrics
        ->roles()
        ->create([
            'role' => RoleName::Metrics,
            'status' => LifecycleStatus::Provisioning,
        ]);
    $gateway = metricsExporterLifecycleNode('gateway', '10.44.0.1');
    $gateway
        ->roles()
        ->create([
            'role' => RoleName::Gateway,
            'status' => LifecycleStatus::Active,
        ]);
    $prospective = metricsExporterLifecycleNode('app-dev', '10.44.0.2');
    $prospective
        ->roles()
        ->create([
            'role' => RoleName::AppDev,
            'status' => LifecycleStatus::Provisioning,
        ]);
    $disabled = metricsExporterLifecycleNode('app-prod', '10.44.0.4');
    $disabled
        ->roles()
        ->create([
            'role' => RoleName::AppProd,
            'status' => LifecycleStatus::Active,
        ]);
    $roleless = metricsExporterLifecycleNode('orbit-ops', '10.44.0.5');
    $preferences = app(ExporterPreferenceRepository::class);
    $preferences->put($disabled->id, ExporterPreference::Disabled);
    $preferences->put($roleless->id, ExporterPreference::Enabled);
    $lifecycle = new NativeMetricsExporterLifecycle(
        executor: new MetricsExporterSshExecutor(
            ssh: app(SshExecutor::class),
            keys: app(SshKeyProvider::class),
            knownHosts: app(KnownHostsStore::class),
        ),
        selector: new ExporterSelector,
        preferences: $preferences,
    );

    expect($lifecycle->targets($metrics))->toBe([
        ['name' => 'app-dev', 'address' => '10.44.0.2'],
        ['name' => 'gateway', 'address' => '10.44.0.1'],
        ['name' => 'metrics', 'address' => '10.44.0.3'],
        ['name' => 'orbit-ops', 'address' => '10.44.0.5'],
    ]);
});

it('fails closed when a selected node has no valid WireGuard address', function (): void {
    $metrics = metricsExporterLifecycleNode('metrics', '10.44.0.3');
    $metrics
        ->roles()
        ->create([
            'role' => RoleName::Metrics,
            'status' => LifecycleStatus::Active,
        ]);
    $invalid = metricsExporterLifecycleNode('invalid-target', '10.44.0.4');
    $invalid->update(['wireguard_address' => null]);
    $invalid
        ->roles()
        ->create([
            'role' => RoleName::AppDev,
            'status' => LifecycleStatus::Active,
        ]);
    $lifecycle = new NativeMetricsExporterLifecycle(
        executor: new MetricsExporterSshExecutor(
            ssh: app(SshExecutor::class),
            keys: app(SshKeyProvider::class),
            knownHosts: app(KnownHostsStore::class),
        ),
        selector: new ExporterSelector,
        preferences: app(ExporterPreferenceRepository::class),
    );

    expect(fn () => $lifecycle->targets($metrics))
        ->toThrow(ResourceOperationException::class, 'valid WireGuard address');
});

it('restores every earlier exporter mutation when a later fleet node fails', function (): void {
    $metrics = metricsExporterLifecycleNode('metrics', '10.44.0.3');
    $assignment = $metrics
        ->roles()
        ->create([
            'role' => RoleName::Metrics,
            'status' => LifecycleStatus::Active,
        ]);
    $early = metricsExporterLifecycleNode('early', '10.44.0.4');
    $early
        ->roles()
        ->create([
            'role' => RoleName::AppDev,
            'status' => LifecycleStatus::Active,
        ]);
    $later = metricsExporterLifecycleNode('later', '10.44.0.5');
    $later
        ->roles()
        ->create([
            'role' => RoleName::AppProd,
            'status' => LifecycleStatus::Active,
        ]);
    $runtime = new MetricsExporterFleetRuntimeFake('later');
    $lifecycle = new NativeMetricsExporterLifecycle(
        executor: $runtime,
        selector: new ExporterSelector,
        preferences: app(ExporterPreferenceRepository::class),
    );

    expect(fn () => $lifecycle->converge($metrics, $assignment))
        ->toThrow(ResourceOperationException::class, 'later exporter failed');
    expect($runtime->events)->toBe([
        'snapshot:metrics',
        'snapshot:early',
        'snapshot:later',
        'converge:metrics',
        'converge:early',
        'converge:later',
        'restore:later',
        'restore:early',
        'restore:metrics',
    ]);
});

function metricsExporterLifecycleNode(string $name, string $address): Node
{
    return Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => "192.0.2.{$name}",
        'ssh_user' => 'orbit',
        'wireguard_address' => $address,
    ]);
}

final class MetricsExporterFleetRuntimeFake implements MetricsExporterRuntime
{
    /** @var list<string> */
    public array $events = [];

    public function __construct(
        private readonly string $failingNode,
    ) {}

    public function snapshot(Node $node, Node $metricsNode): MetricsExporterState
    {
        $this->events[] = "snapshot:{$node->name}";

        return new MetricsExporterState(null, false, UfwRuleOwnership::Missing);
    }

    public function converge(Node $node, Node $metricsNode): void
    {
        $this->events[] = "converge:{$node->name}";

        if ($node->name === $this->failingNode) {
            throw new ResourceOperationException('metrics.exporter_failed', 'The later exporter failed.', 502);
        }
    }

    public function remove(Node $node, Node $metricsNode): void
    {
        $this->events[] = "remove:{$node->name}";
    }

    public function restore(Node $node, Node $metricsNode, MetricsExporterState $state): void
    {
        $this->events[] = "restore:{$node->name}";
    }

    public function actual(Node $node, Node $metricsNode): string
    {
        return 'inactive';
    }
}
