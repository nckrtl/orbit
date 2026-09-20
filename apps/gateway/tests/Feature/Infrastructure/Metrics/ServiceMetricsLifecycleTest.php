<?php

declare(strict_types=1);

use App\Domain\Metrics\ExporterDegradationRepository;
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

it('does not publish targets after a failed service and also restores that partial mutation', function (): void {
    $metrics = service_metrics_lifecycle_node('metrics');
    $runtime = service_metrics_recording_runtime();
    $runtime->failConverge = true;
    $degraded = app(ExporterDegradationRepository::class);
    $lifecycle = new NativeServiceMetricsLifecycle(app(ServiceMetricsProjection::class), $runtime, $degraded);

    expect(fn () => $lifecycle->converge($metrics, function () use ($runtime): void {
        $runtime->events[] = 'publish';
    }))->toThrow(RuntimeException::class, 'service failed');

    expect($runtime->events)->toBe(['snapshot:'.$metrics->id, 'converge:'.$metrics->id, 'restore:'.$metrics->id]);
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

        public bool $failConverge = false;

        public function snapshot(ServiceMetricsNode $target): string
        {
            $this->events[] = 'snapshot:'.$target->node->id;

            return 'snapshot:'.$target->node->id;
        }

        public function converge(ServiceMetricsNode $target, Node $metricsNode): void
        {
            $this->events[] = 'converge:'.$target->node->id;
            if ($this->failConverge) {
                throw new RuntimeException('service failed');
            }
        }

        public function restore(ServiceMetricsNode $target, string $snapshot): void
        {
            expect($snapshot)->toBe('snapshot:'.$target->node->id);
            $this->events[] = 'restore:'.$target->node->id;
        }
    };
}
