<?php

declare(strict_types=1);

use App\Domain\Metrics\ExporterDegradationReason;
use App\Domain\Metrics\ExporterDegradationRepository;
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
use App\Models\NodeRole;

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
        degradations: app(ExporterDegradationRepository::class),
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
        degradations: app(ExporterDegradationRepository::class),
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
        degradations: app(ExporterDegradationRepository::class),
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

it('skips a fleet node it cannot inspect and records why', function (): void {
    [$metrics, $assignment, $reachable, $dead] = metricsExporterDegradationFleet();
    $degradations = app(ExporterDegradationRepository::class);
    $runtime = new MetricsExporterDegradingRuntimeFake([
        'dead' => new ResourceOperationException(
            'metrics.exporter_configuration_inspection_failed',
            'The Metrics exporter configuration could not be inspected.',
            502,
        ),
    ]);

    new NativeMetricsExporterLifecycle(
        executor: $runtime,
        selector: new ExporterSelector,
        preferences: app(ExporterPreferenceRepository::class),
        degradations: $degradations,
    )->converge($metrics, $assignment);

    expect($runtime->events)
        ->toBe([
            'snapshot:metrics',
            'snapshot:reachable',
            'snapshot-failed:dead',
            'converge:metrics',
            'converge:reachable',
        ])
        ->and($degradations->get($dead->id))
        ->toBe(ExporterDegradationReason::Unreachable)
        ->and($degradations->get($reachable->id))
        ->toBeNull();
});

it('degrades a fleet node whose firewall is inactive', function (): void {
    [$metrics, $assignment, , $dead] = metricsExporterDegradationFleet();
    $degradations = app(ExporterDegradationRepository::class);

    new NativeMetricsExporterLifecycle(
        executor: new MetricsExporterDegradingRuntimeFake([
            'dead' => new ResourceOperationException(
                'metrics.exporter_firewall_inactive',
                'UFW must be active for Metrics exporter convergence.',
                409,
            ),
        ]),
        selector: new ExporterSelector,
        preferences: app(ExporterPreferenceRepository::class),
        degradations: $degradations,
    )->converge($metrics, $assignment);

    expect($degradations->get($dead->id))->toBe(ExporterDegradationReason::FirewallInactive);
});

it('fails closed when the Metrics node itself cannot be inspected', function (): void {
    [$metrics, $assignment] = metricsExporterDegradationFleet();
    $degradations = app(ExporterDegradationRepository::class);
    $lifecycle = new NativeMetricsExporterLifecycle(
        executor: new MetricsExporterDegradingRuntimeFake([
            'metrics' => new ResourceOperationException(
                'metrics.exporter_configuration_inspection_failed',
                'The Metrics exporter configuration could not be inspected.',
                502,
            ),
        ]),
        selector: new ExporterSelector,
        preferences: app(ExporterPreferenceRepository::class),
        degradations: $degradations,
    );

    expect(fn () => $lifecycle->converge($metrics, $assignment))
        ->toThrow(ResourceOperationException::class, 'could not be inspected');
    expect($degradations->get($metrics->id))->toBeNull();
});

it('keeps failing closed when a fleet node cannot prove exporter ownership', function (): void {
    [$metrics, $assignment, , $dead] = metricsExporterDegradationFleet();
    $degradations = app(ExporterDegradationRepository::class);
    $lifecycle = new NativeMetricsExporterLifecycle(
        executor: new MetricsExporterDegradingRuntimeFake([
            'dead' => new ResourceOperationException(
                'metrics.exporter_configuration_ownership_drift',
                'Metrics exporter configuration ownership cannot be proved.',
                409,
            ),
        ]),
        selector: new ExporterSelector,
        preferences: app(ExporterPreferenceRepository::class),
        degradations: $degradations,
    );

    expect(fn () => $lifecycle->converge($metrics, $assignment))
        ->toThrow(ResourceOperationException::class, 'ownership cannot be proved');
    expect($degradations->get($dead->id))->toBeNull();
});

it('clears a recorded degradation once the node can be inspected again', function (): void {
    [$metrics, $assignment, , $dead] = metricsExporterDegradationFleet();
    $degradations = app(ExporterDegradationRepository::class);
    $degradations->put($dead->id, ExporterDegradationReason::Unreachable);

    new NativeMetricsExporterLifecycle(
        executor: new MetricsExporterDegradingRuntimeFake,
        selector: new ExporterSelector,
        preferences: app(ExporterPreferenceRepository::class),
        degradations: $degradations,
    )->converge($metrics, $assignment);

    expect($degradations->get($dead->id))->toBeNull();
});

it('forgets a retired node degradation even when its exporter cannot be removed', function (): void {
    [$metrics, , , $dead] = metricsExporterDegradationFleet();
    $degradations = app(ExporterDegradationRepository::class);
    $degradations->put($dead->id, ExporterDegradationReason::Unreachable);
    $lifecycle = new NativeMetricsExporterLifecycle(
        executor: new MetricsExporterDegradingRuntimeFake(removeFailures: ['dead']),
        selector: new ExporterSelector,
        preferences: app(ExporterPreferenceRepository::class),
        degradations: $degradations,
    );

    expect(fn () => $lifecycle->removeNode($dead, $metrics))
        ->toThrow(ResourceOperationException::class, 'The dead exporter failed.');
    expect($degradations->get($dead->id))->toBeNull();
});

/**
 * A three node fleet: the Metrics node, one healthy peer, and one peer the
 * degradation fakes can fail.
 *
 * @return array{0: Node, 1: NodeRole, 2: Node, 3: Node}
 */
function metricsExporterDegradationFleet(): array
{
    $metrics = metricsExporterLifecycleNode('metrics', '10.44.0.1');
    $assignment = $metrics
        ->roles()
        ->create([
            'role' => RoleName::Metrics,
            'status' => LifecycleStatus::Active,
        ]);
    $reachable = metricsExporterLifecycleNode('reachable', '10.44.0.2');
    $reachable
        ->roles()
        ->create([
            'role' => RoleName::AppDev,
            'status' => LifecycleStatus::Active,
        ]);
    $dead = metricsExporterLifecycleNode('dead', '10.44.0.3');
    $dead
        ->roles()
        ->create([
            'role' => RoleName::AppProd,
            'status' => LifecycleStatus::Active,
        ]);

    return [$metrics, $assignment, $reachable, $dead];
}

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

final class MetricsExporterDegradingRuntimeFake implements MetricsExporterRuntime
{
    /** @var list<string> */
    public array $events = [];

    /**
     * @param array<string, ResourceOperationException> $snapshotFailures keyed by node name
     * @param list<string> $removeFailures node names whose removal fails
     */
    public function __construct(
        private readonly array $snapshotFailures = [],
        private readonly array $removeFailures = [],
    ) {}

    public function snapshot(Node $node, Node $metricsNode): MetricsExporterState
    {
        $failure = $this->snapshotFailures[$node->name] ?? null;

        if ($failure instanceof ResourceOperationException) {
            $this->events[] = "snapshot-failed:{$node->name}";

            throw $failure;
        }

        $this->events[] = "snapshot:{$node->name}";

        return new MetricsExporterState(null, false, UfwRuleOwnership::Missing);
    }

    public function converge(Node $node, Node $metricsNode): void
    {
        $this->events[] = "converge:{$node->name}";
    }

    public function remove(Node $node, Node $metricsNode): void
    {
        $this->events[] = "remove:{$node->name}";

        if (in_array($node->name, $this->removeFailures, strict: true)) {
            throw new ResourceOperationException(
                'metrics.exporter_remove_failed',
                "The {$node->name} exporter failed.",
                502,
            );
        }
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
