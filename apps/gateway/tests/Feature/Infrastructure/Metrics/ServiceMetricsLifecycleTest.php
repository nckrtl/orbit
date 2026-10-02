<?php

declare(strict_types=1);

use App\Domain\Metrics\ExporterDegradationReason;
use App\Domain\Metrics\ExporterDegradationRepository;
use App\Domain\Metrics\MetricsCadvisorLifecycle;
use App\Domain\Metrics\MetricsExporterLifecycle;
use App\Domain\Metrics\MetricsExporterProjection;
use App\Domain\Metrics\MetricsFleetReconcileException;
use App\Domain\Metrics\MetricsReconcileDegradationRepository;
use App\Domain\Metrics\MetricsRuntimeLifecycle;
use App\Domain\Nodes\NodeLockLoss;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Firewall\UfwRuleOwnership;
use App\Infrastructure\Metrics\MetricsCadvisorRuntime;
use App\Infrastructure\Metrics\MetricsExporterRuntime;
use App\Infrastructure\Metrics\MetricsExporterState;
use App\Infrastructure\Metrics\NativeMetricsCadvisorLifecycle;
use App\Infrastructure\Metrics\NativeMetricsExporterLifecycle;
use App\Infrastructure\Metrics\NativeMetricsFleetReconciler;
use App\Infrastructure\Metrics\NativeServiceMetricsLifecycle;
use App\Infrastructure\Metrics\ServiceMetricsNode;
use App\Infrastructure\Metrics\ServiceMetricsProjection;
use App\Infrastructure\Metrics\ServiceMetricsRuntime;
use App\Models\Node;

it('restores service snapshots in reverse order when target publication fails', function (): void {
    $metrics = service_metrics_lifecycle_node('metrics');
    $first = service_metrics_lifecycle_node('first');
    $second = service_metrics_lifecycle_node('second');
    $runtime = service_metrics_recording_runtime();
    $degraded = app(ExporterDegradationRepository::class);
    $lifecycle = new NativeServiceMetricsLifecycle(app(ServiceMetricsProjection::class), $runtime, $degraded);

    expect(fn () => $lifecycle->converge($metrics, function () use ($runtime): void {
        $runtime->events[] = 'publish';
        throw new RuntimeException('publication failed');
    }))->toThrow(RuntimeException::class, 'publication failed');

    expect($runtime->events)->toBe([
        'snapshot:'.$metrics->id, 'snapshot:'.$first->id, 'snapshot:'.$second->id,
        'converge:'.$metrics->id, 'converge:'.$first->id, 'converge:'.$second->id,
        'publish', 'restore:'.$second->id, 'restore:'.$first->id, 'restore:'.$metrics->id,
    ]);
});

it('degrades a Node whose service snapshot fails and finishes the fleet reconcile', function (): void {
    $metrics = activate_metrics_role(service_metrics_lifecycle_node('metrics'));
    $failed = service_metrics_lifecycle_node('failed');
    $healthy = service_metrics_lifecycle_node('healthy');
    $runtime = service_metrics_recording_runtime();
    $runtime->failSnapshot = true;
    $runtime->failedNodeId = $failed->id;
    $degraded = app(ExporterDegradationRepository::class);
    $services = new NativeServiceMetricsLifecycle(app(ServiceMetricsProjection::class), $runtime, $degraded);
    $exporters = Mockery::mock(MetricsExporterLifecycle::class);
    $cadvisors = Mockery::mock(MetricsCadvisorLifecycle::class);
    $publication = Mockery::mock(MetricsRuntimeLifecycle::class);
    $exporters->shouldReceive('converge')->twice();
    $cadvisors->shouldReceive('converge')->twice();
    $publication->shouldReceive('converge')->twice()->andReturnUsing(function () use ($runtime): void {
        $runtime->events[] = 'publish';
    });
    $fleet = new NativeMetricsFleetReconciler($exporters, $cadvisors, $publication, $services);

    $fleet->reconcile();

    expect($runtime->events)->toBe([
        'snapshot:'.$metrics->id, 'snapshot:'.$failed->id, 'snapshot:'.$healthy->id,
        'converge:'.$metrics->id, 'converge:'.$healthy->id, 'publish',
    ]);
    expect($degraded->get($failed->id))->toBe(ExporterDegradationReason::ReconcileFailed);
    expect($degraded->step($failed->id))->toBe('snapshot');
    expect(app(MetricsReconcileDegradationRepository::class)->errorCode($failed->id))->toBe('metrics.service_convergence_failed');
    expect($degraded->get($healthy->id))->toBeNull();

    $runtime->failSnapshot = false;
    $runtime->events = [];
    $fleet->reconcile();

    expect($runtime->events)->toBe([
        'snapshot:'.$metrics->id, 'snapshot:'.$failed->id, 'snapshot:'.$healthy->id,
        'converge:'.$metrics->id, 'converge:'.$failed->id, 'converge:'.$healthy->id, 'publish',
    ]);
    expect($degraded->get($failed->id))->toBeNull();
    expect($degraded->step($failed->id))->toBeNull();
    expect(app(MetricsReconcileDegradationRepository::class)->errorCode($failed->id))->toBeNull();
});

it('restores a failed service partial mutation and publishes the healthy Nodes', function (): void {
    $metrics = service_metrics_lifecycle_node('metrics');
    $runtime = service_metrics_recording_runtime();
    $failed = service_metrics_lifecycle_node('failed');
    $healthy = service_metrics_lifecycle_node('healthy');
    $runtime->failConverge = true;
    $runtime->failedNodeId = $failed->id;
    $degraded = app(ExporterDegradationRepository::class);
    $lifecycle = new NativeServiceMetricsLifecycle(app(ServiceMetricsProjection::class), $runtime, $degraded);

    $lifecycle->converge($metrics, function () use ($runtime): void {
        $runtime->events[] = 'publish';
    });

    expect($runtime->events)->toBe([
        'snapshot:'.$metrics->id, 'snapshot:'.$failed->id, 'snapshot:'.$healthy->id,
        'converge:'.$metrics->id, 'converge:'.$failed->id, 'restore:'.$failed->id,
        'converge:'.$healthy->id, 'publish',
    ]);
    expect($degraded->get($failed->id))->toBe(ExporterDegradationReason::ReconcileFailed);
    expect($degraded->step($failed->id))->toBe('converge');
    expect(app(MetricsReconcileDegradationRepository::class)->errorCode($failed->id))->toBe('metrics.service_reconcile_failed');
});

it('stops the operation and preserves the code when a Node lock is lost', function (string $step): void {
    $metrics = service_metrics_lifecycle_node('metrics');
    $failed = service_metrics_lifecycle_node('failed');
    $healthy = service_metrics_lifecycle_node('healthy');
    $runtime = service_metrics_recording_runtime();
    $runtime->failedNodeId = $failed->id;
    $runtime->lockLostAt = $step;
    $runtime->failSnapshot = $step === 'snapshot';
    $runtime->failConverge = in_array($step, ['converge', 'restore'], true);
    $degraded = app(ExporterDegradationRepository::class);
    $degraded->recordReconcileFailure($failed->id, 'snapshot', 'metrics.service_inspection_failed');
    $lifecycle = new NativeServiceMetricsLifecycle(app(ServiceMetricsProjection::class), $runtime, $degraded);

    expect(fn () => $lifecycle->converge($metrics, function () use ($runtime, $step): void {
        $runtime->events[] = 'publish';
        if ($step === 'publication-restore') {
            throw new RuntimeException('publication failed');
        }
        throw new RuntimeException('wrapped lock loss', 0, NodeLockLoss::exception('node-role:id:1'));
    }))->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe(NodeLockLoss::ErrorCode));

    $snapshots = ['snapshot:'.$metrics->id, 'snapshot:'.$failed->id, 'snapshot:'.$healthy->id];
    expect($runtime->events)->toBe(match ($step) {
        'snapshot' => ['snapshot:'.$metrics->id, 'snapshot:'.$failed->id],
        'converge' => [...$snapshots, 'converge:'.$metrics->id, 'converge:'.$failed->id],
        'restore' => [...$snapshots, 'converge:'.$metrics->id, 'converge:'.$failed->id, 'restore:'.$failed->id],
        'publish' => [...$snapshots, 'converge:'.$metrics->id, 'converge:'.$failed->id, 'converge:'.$healthy->id, 'publish'],
        'publication-restore' => [...$snapshots, 'converge:'.$metrics->id, 'converge:'.$failed->id, 'converge:'.$healthy->id, 'publish', 'restore:'.$healthy->id],
    });
    expect($degraded->step($failed->id))->toBe('snapshot');
    expect(app(MetricsReconcileDegradationRepository::class)->errorCode($failed->id))->toBe('metrics.service_inspection_failed');
})->with(['snapshot', 'converge', 'restore', 'publish', 'publication-restore']);

it('records a failed service recovery and still converges the remaining Nodes', function (): void {
    $metrics = service_metrics_lifecycle_node('metrics');
    $failed = service_metrics_lifecycle_node('failed');
    $healthy = service_metrics_lifecycle_node('healthy');
    $runtime = service_metrics_recording_runtime();
    $runtime->failedNodeId = $failed->id;
    $runtime->failConverge = true;
    $runtime->failRestore = true;
    $degraded = app(ExporterDegradationRepository::class);
    $lifecycle = new NativeServiceMetricsLifecycle(app(ServiceMetricsProjection::class), $runtime, $degraded);

    $lifecycle->converge($metrics);

    expect($runtime->events)->toBe([
        'snapshot:'.$metrics->id, 'snapshot:'.$failed->id, 'snapshot:'.$healthy->id,
        'converge:'.$metrics->id, 'converge:'.$failed->id, 'restore:'.$failed->id,
        'converge:'.$healthy->id,
    ]);
    expect($degraded->step($failed->id))->toBe('restore');
    expect(app(MetricsReconcileDegradationRepository::class)->errorCode($failed->id))->toBe('metrics.service_rollback_failed');
});

it('rolls back healthy Nodes when publication fails after a service Node degraded', function (): void {
    $metrics = service_metrics_lifecycle_node('metrics');
    $failed = service_metrics_lifecycle_node('failed');
    $healthy = service_metrics_lifecycle_node('healthy');
    $runtime = service_metrics_recording_runtime();
    $runtime->failedNodeId = $failed->id;
    $runtime->failConverge = true;
    $lifecycle = new NativeServiceMetricsLifecycle(app(ServiceMetricsProjection::class), $runtime, app(ExporterDegradationRepository::class));

    expect(fn () => $lifecycle->converge($metrics, function (): void {
        throw new RuntimeException('publication failed');
    }))->toThrow(RuntimeException::class, 'publication failed');

    expect($runtime->events)->toBe([
        'snapshot:'.$metrics->id, 'snapshot:'.$failed->id, 'snapshot:'.$healthy->id,
        'converge:'.$metrics->id, 'converge:'.$failed->id, 'restore:'.$failed->id,
        'converge:'.$healthy->id, 'restore:'.$healthy->id, 'restore:'.$metrics->id,
    ]);
    expect(app(ExporterDegradationRepository::class)->step($failed->id))->toBe('converge');
});

it('still reports a publication rollback failure', function (): void {
    $metrics = service_metrics_lifecycle_node('metrics');
    $runtime = service_metrics_recording_runtime();
    $runtime->failRestore = true;
    $lifecycle = new NativeServiceMetricsLifecycle(app(ServiceMetricsProjection::class), $runtime, app(ExporterDegradationRepository::class));

    expect(fn () => $lifecycle->converge($metrics, function (): void {
        throw new RuntimeException('publication failed');
    }))->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe('metrics.service_rollback_failed'));
});

it('skips a Node already degraded by exporter reconciliation', function (): void {
    $metrics = service_metrics_lifecycle_node('metrics');
    $skipped = service_metrics_lifecycle_node('skipped');
    $runtime = service_metrics_recording_runtime();
    $degraded = app(ExporterDegradationRepository::class);
    $degraded->recordReconcileFailure($skipped->id, 'snapshot', 'metrics.service_inspection_failed');
    $degraded->put($skipped->id, ExporterDegradationReason::Unreachable);
    $lifecycle = new NativeServiceMetricsLifecycle(app(ServiceMetricsProjection::class), $runtime, $degraded);

    $lifecycle->converge($metrics);

    expect($runtime->events)->toBe(['snapshot:'.$metrics->id, 'converge:'.$metrics->id]);
    expect($degraded->get($skipped->id))->toBe(ExporterDegradationReason::Unreachable);
    expect($degraded->step($skipped->id))->toBe('snapshot');
    expect(app(MetricsReconcileDegradationRepository::class)->errorCode($skipped->id))->toBe('metrics.service_inspection_failed');
});

it('degrades failed snapshots during service removal', function (bool $singleNode): void {
    $metrics = service_metrics_lifecycle_node('metrics');
    $failed = service_metrics_lifecycle_node('failed');
    $runtime = service_metrics_recording_runtime();
    $runtime->failSnapshot = true;
    $runtime->failedNodeId = $failed->id;
    $degraded = app(ExporterDegradationRepository::class);
    $lifecycle = new NativeServiceMetricsLifecycle(app(ServiceMetricsProjection::class), $runtime, $degraded);

    $singleNode ? $lifecycle->removeNode($failed, $metrics) : $lifecycle->remove($metrics);

    expect($degraded->get($failed->id))->toBe(ExporterDegradationReason::ReconcileFailed);
    expect($degraded->step($failed->id))->toBe('snapshot');
    expect($runtime->events)->toBe($singleNode
        ? ['snapshot:'.$failed->id]
        : ['snapshot:'.$metrics->id, 'snapshot:'.$failed->id, 'converge:'.$metrics->id]);
})->with([true, false]);

it('keeps an unrecovered service failure through native exporter and cAdvisor snapshots', function (string $failedComponent): void {
    $metrics = activate_metrics_role(service_metrics_lifecycle_node('metrics'));
    $recovering = service_metrics_lifecycle_node('recovering');
    $recovering->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
    $degraded = app(ExporterDegradationRepository::class);
    $degraded->recordReconcileFailure($recovering->id, 'restore', 'metrics.service_rollback_failed');
    $state = new MetricsExporterState(null, false, UfwRuleOwnership::Missing);
    $exporterRuntime = Mockery::mock(MetricsExporterRuntime::class);
    $cadvisorRuntime = Mockery::mock(MetricsCadvisorRuntime::class);
    $exporterRuntime->shouldReceive('snapshot')->twice()->andReturn($state);
    $exporterRuntime->shouldReceive('converge')->andReturnUsing(function () use ($failedComponent): void {
        if ($failedComponent === 'exporter') {
            throw new RuntimeException('exporter convergence failed');
        }
    });
    $exporterRuntime->shouldReceive('restore');
    if ($failedComponent === 'cadvisor') {
        $cadvisorRuntime->shouldReceive('snapshot')->twice()->andReturn($state);
        $cadvisorRuntime->shouldReceive('converge')->once()->andThrow(new RuntimeException('cAdvisor convergence failed'));
        $cadvisorRuntime->shouldReceive('restore')->once();
    }
    $serviceRuntime = service_metrics_recording_runtime();
    $fleet = new NativeMetricsFleetReconciler(
        new NativeMetricsExporterLifecycle($exporterRuntime, app(MetricsExporterProjection::class), $degraded),
        new NativeMetricsCadvisorLifecycle($cadvisorRuntime, app(MetricsExporterProjection::class), $degraded),
        Mockery::mock(MetricsRuntimeLifecycle::class),
        new NativeServiceMetricsLifecycle(app(ServiceMetricsProjection::class), $serviceRuntime, $degraded),
    );

    expect(fn () => $fleet->reconcile())->toThrow(MetricsFleetReconcileException::class);

    expect($serviceRuntime->events)->toBe([]);
    expect($degraded->get($recovering->id))->toBe(ExporterDegradationReason::ReconcileFailed);
    expect($degraded->step($recovering->id))->toBe('restore');
    expect(app(MetricsReconcileDegradationRepository::class)->errorCode($recovering->id))->toBe('metrics.service_rollback_failed');
})->with(['exporter', 'cadvisor']);

it('keeps service degradation after a retry publication rollback and clears it only on commit', function (): void {
    $metrics = activate_metrics_role(service_metrics_lifecycle_node('metrics'));
    $recovering = service_metrics_lifecycle_node('recovering');
    $recovering->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
    $degraded = app(ExporterDegradationRepository::class);
    $degraded->recordReconcileFailure($recovering->id, 'snapshot', 'metrics.service_inspection_failed');
    $state = new MetricsExporterState(null, false, UfwRuleOwnership::Missing);
    $exporterRuntime = Mockery::mock(MetricsExporterRuntime::class);
    $cadvisorRuntime = Mockery::mock(MetricsCadvisorRuntime::class);
    foreach ([$exporterRuntime, $cadvisorRuntime] as $runtime) {
        $runtime->shouldReceive('snapshot')->times(4)->andReturn($state);
        $runtime->shouldReceive('converge')->times(4);
    }
    $serviceRuntime = service_metrics_recording_runtime();
    $publication = Mockery::mock(MetricsRuntimeLifecycle::class);
    $publication->shouldReceive('converge')->once()->andThrow(new RuntimeException('publication failed'));
    $publication->shouldReceive('converge')->once()->andReturnUsing(function () use ($degraded, $recovering): void {
        expect($degraded->step($recovering->id))->toBe('snapshot');
    });
    $fleet = new NativeMetricsFleetReconciler(
        new NativeMetricsExporterLifecycle($exporterRuntime, app(MetricsExporterProjection::class), $degraded),
        new NativeMetricsCadvisorLifecycle($cadvisorRuntime, app(MetricsExporterProjection::class), $degraded),
        $publication,
        new NativeServiceMetricsLifecycle(app(ServiceMetricsProjection::class), $serviceRuntime, $degraded),
    );

    expect(fn () => $fleet->reconcile())->toThrow(MetricsFleetReconcileException::class, 'Metrics runtime reconciliation failed.');

    expect($serviceRuntime->events)->toBe([
        'snapshot:'.$metrics->id, 'snapshot:'.$recovering->id,
        'converge:'.$metrics->id, 'converge:'.$recovering->id,
        'restore:'.$recovering->id, 'restore:'.$metrics->id,
    ]);
    expect($degraded->get($recovering->id))->toBe(ExporterDegradationReason::ReconcileFailed);
    expect($degraded->step($recovering->id))->toBe('snapshot');
    expect(app(MetricsReconcileDegradationRepository::class)->errorCode($recovering->id))->toBe('metrics.service_inspection_failed');

    $fleet->reconcile();

    expect($degraded->get($recovering->id))->toBeNull();
    expect($degraded->step($recovering->id))->toBeNull();
    expect(app(MetricsReconcileDegradationRepository::class)->errorCode($recovering->id))->toBeNull();
});

function service_metrics_lifecycle_node(string $name): Node
{
    return Node::query()->create(['name' => $name, 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.81', 'wireguard_ip' => '10.44.0.'.(Node::query()->count() + 10), 'ssh_host_fingerprint' => 'SHA256:metrics-proof']);
}

function service_metrics_recording_runtime(): ServiceMetricsRuntime
{
    return new class implements ServiceMetricsRuntime
    {
        public array $events = [];

        public bool $failSnapshot = false;

        public ?int $failedNodeId = null;

        public bool $failConverge = false;

        public ?string $lockLostAt = null;

        public bool $failRestore = false;

        public function snapshot(ServiceMetricsNode $target): string
        {
            $this->events[] = 'snapshot:'.$target->node->id;
            if ($this->failSnapshot && $target->node->id === $this->failedNodeId) {
                throw $this->lockLostAt === 'snapshot'
                    ? new RuntimeException('wrapped lock loss', 0, NodeLockLoss::exception('node-role:id:1'))
                    : new ResourceOperationException('metrics.service_convergence_failed', 'Service metrics command failed.', 502);
            }

            return 'snapshot:'.$target->node->id;
        }

        public function converge(ServiceMetricsNode $target, Node $metricsNode): void
        {
            $this->events[] = 'converge:'.$target->node->id;
            if ($this->failConverge && ($this->failedNodeId === null || $this->failedNodeId === $target->node->id)) {
                throw $this->lockLostAt === 'converge'
                    ? new RuntimeException('wrapped lock loss', 0, NodeLockLoss::exception('node-role:id:1'))
                    : new RuntimeException('service failed');
            }
        }

        public function restore(ServiceMetricsNode $target, string $snapshot): void
        {
            expect($snapshot)->toBe('snapshot:'.$target->node->id);
            $this->events[] = 'restore:'.$target->node->id;

            if ($this->lockLostAt === 'restore' || $this->lockLostAt === 'publication-restore') {
                throw new RuntimeException('wrapped lock loss', 0, NodeLockLoss::exception('node-role:id:1'));
            }
            if ($this->failRestore) {
                throw new RuntimeException('monitoring recovery reload failed');
            }
        }
    };
}
