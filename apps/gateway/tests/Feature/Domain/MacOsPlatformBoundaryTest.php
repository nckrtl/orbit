<?php

declare(strict_types=1);

use App\Actions\Firewall\RemoveFirewallRuleAction;
use App\Actions\Firewall\StoreFirewallRuleAction;
use App\Actions\Nodes\AddNodeRoleAction;
use App\Actions\Processes\AddProcessAction;
use App\Actions\Schedules\AddScheduleAction;
use App\Data\Firewall\StoreFirewallRuleData;
use App\Data\Processes\AddProcessData;
use App\Data\Schedules\AddScheduleData;
use App\Domain\Firewall\FirewallAction;
use App\Domain\Firewall\FirewallBackendStatus;
use App\Domain\Firewall\FirewallManager;
use App\Domain\Firewall\FirewallOperationException;
use App\Domain\Metrics\ExporterPreferenceRepository;
use App\Domain\Metrics\MetricsFleetReconciler;
use App\Domain\Nodes\NodeConverger;
use App\Domain\Nodes\NodeObservation;
use App\Domain\Nodes\NodeProvisioningIdentity;
use App\Domain\Nodes\RecoverableNodeConverger;
use App\Domain\Nodes\RoleName;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessTargetType;
use App\Domain\Schedules\ScheduleTargetResolver;
use App\Domain\Schedules\ScheduleTargetType;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Metrics\NativeMetricsRoleManager;
use App\Models\FirewallRule;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Process;
use App\Models\Schedule;
use Tests\Support\Schedules\FakeScheduleRuntimeAccountResolver;

it('refuses macOS role, Metrics, Process, Schedule, and firewall changes before Linux mutation', function (): void {
    app()->instance(NodeConverger::class, new MacOsBoundaryConverger);
    $fleet = Mockery::mock(MetricsFleetReconciler::class);
    $fleet->shouldNotReceive('reconcile');
    $fleet->shouldNotReceive('retire');
    app()->instance(MetricsFleetReconciler::class, $fleet);
    $node = macos_boundary_node();
    $firewall = new MacOsBoundaryFirewall;

    expect(fn () => app(AddNodeRoleAction::class)->execute($node, RoleName::AppDev))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('node.platform_unsupported')
                ->and($exception->status)->toBe(422);
        });
    expect(fn () => app(AddNodeRoleAction::class)->executeDuringProvisioning($node, RoleName::AppDev))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('node.platform_unsupported')
                ->and($exception->status)->toBe(422);
        });
    expect(fn () => app(NativeMetricsRoleManager::class)->enable($node->id))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('metrics.platform_unsupported')
                ->and($exception->status)->toBe(422);
        });
    expect(fn () => app(NativeMetricsRoleManager::class)->enableExporter($node->id))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('metrics.platform_unsupported')
                ->and($exception->status)->toBe(422);
        });
    expect(fn () => app(NativeMetricsRoleManager::class)->disableExporter($node->id))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('metrics.platform_unsupported')
                ->and($exception->status)->toBe(422);
        });
    expect(fn () => app(AddProcessAction::class)->execute(new AddProcessData(
        targetType: ProcessTargetType::Node,
        targetId: $node->id,
        name: 'queue',
        runtime: ProcessRuntime::Systemd,
        command: ['/usr/bin/php', 'artisan', 'queue:work'],
        image: null,
        workingDirectory: null,
        environment: [],
        ports: [],
        volumes: [],
        restartPolicy: 'always',
        start: false,
    )))->toThrow(function (ResourceOperationException $exception): void {
        expect($exception->errorCode)->toBe('process.platform_unsupported')
            ->and($exception->status)->toBe(422);
    });
    expect(fn () => app(AddScheduleAction::class)->execute(new AddScheduleData(
        ScheduleTargetType::Node,
        $node->id,
        'daily-backup',
        'daily',
        'true',
    )))->toThrow(function (ResourceOperationException $exception): void {
        expect($exception->errorCode)->toBe('schedule.platform_unsupported')
            ->and($exception->status)->toBe(422);
    });

    $accounts = new FakeScheduleRuntimeAccountResolver;
    $accounts->unavailable = true;
    expect(fn () => (new ScheduleTargetResolver($accounts))->resolve(ScheduleTargetType::Node, $node->id))
        ->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('schedule.platform_unsupported')
                ->and($exception->status)->toBe(422);
        });

    expect(fn () => (new StoreFirewallRuleAction($firewall))->execute($node, new StoreFirewallRuleData(
        name: 'web',
        action: FirewallAction::Allow,
        source: '192.0.2.0/24',
        protocol: 'tcp',
        port: '443',
    )))->toThrow(function (FirewallOperationException $exception): void {
        expect($exception->errorCode)->toBe('firewall.platform_unsupported')
            ->and($exception->status)->toBe(422);
    });

    $rule = FirewallRule::query()->create([
        'node_id' => $node->id,
        'name' => 'web',
        'action' => FirewallAction::Allow,
        'source' => '192.0.2.0/24',
        'protocol' => 'tcp',
        'port' => '443',
        'status' => LifecycleStatus::Active,
    ]);

    expect(fn () => (new RemoveFirewallRuleAction($firewall))->execute($rule))
        ->toThrow(function (FirewallOperationException $exception): void {
            expect($exception->errorCode)->toBe('firewall.platform_unsupported')
                ->and($exception->status)->toBe(422);
        });

    expect(NodeRole::query()->count())->toBe(0)
        ->and(Process::query()->count())->toBe(0)
        ->and(Schedule::query()->count())->toBe(0)
        ->and(FirewallRule::query()->count())->toBe(1)
        ->and($rule->fresh()?->status)->toBe(LifecycleStatus::Active)
        ->and($firewall->calls)->toBe(0)
        ->and(app(ExporterPreferenceRepository::class)->get($node->id))->toBeNull();
});

function macos_boundary_node(): Node
{
    return Node::query()->create([
        'name' => 'mini',
        'status' => LifecycleStatus::Active,
        'platform' => 'macos',
        'architecture' => 'arm64',
        'public_ssh_host' => '192.0.2.40',
        'user' => 'mini',
        'wireguard_ip' => '10.44.0.40',
        'ssh_host_fingerprint' => 'SHA256:managed-mac',
    ]);
}

final class MacOsBoundaryConverger implements NodeConverger, RecoverableNodeConverger
{
    public function converge(
        Node $node,
        NodeProvisioningIdentity $identity,
        ?string $expectedSshHostFingerprint = null,
        bool $rolelessOperator = false,
    ): NodeObservation {
        throw new RuntimeException('Linux convergence must not start.');
    }

    public function convergeRecoverably(
        Node $node,
        NodeProvisioningIdentity $identity,
        ?string $expectedSshHostFingerprint,
        Closure $completion,
        bool $rolelessOperator = false,
    ): void {
        throw new RuntimeException('Linux convergence must not start.');
    }
}

final class MacOsBoundaryFirewall implements FirewallManager
{
    public int $calls = 0;

    public function converge(FirewallRule $rule): FirewallBackendStatus
    {
        $this->calls++;

        throw new RuntimeException('Firewall mutation must not start.');
    }

    public function remove(FirewallRule $rule): FirewallBackendStatus
    {
        $this->calls++;

        throw new RuntimeException('Firewall mutation must not start.');
    }
}
