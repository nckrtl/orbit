<?php

declare(strict_types=1);

use App\Domain\Metrics\MetricsExporterLifecycle;
use App\Domain\Metrics\MetricsGatewayResolver;
use App\Domain\Metrics\MetricsPublicationCleanup;
use App\Domain\Metrics\MetricsPublicationManager;
use App\Domain\Metrics\MetricsPublicationReport;
use App\Domain\Metrics\MetricsRuntimeLifecycle;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Nodes\Roles\MetricsRoleBaseline;
use App\Models\Node;
use App\Models\NodeRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('converges exporters before runtime and private publication', function (): void {
    [$metrics, $assignment] = metricsBaselineTopology();
    $events = [];
    $baseline = metricsBaseline($events);

    $baseline->converge($metrics, $assignment);

    expect($events)->toBe([
        'exporters:converge',
        'runtime:converge',
        'publication:converge',
    ]);
});

it('rejects missing or duplicate active Gateways before remote effects', function (int $gatewayCount): void {
    [$metrics, $assignment] = metricsBaselineTopology($gatewayCount);
    $events = [];
    $baseline = metricsBaseline($events);

    expect(fn () => $baseline->converge($metrics, $assignment))
        ->toThrow(ResourceOperationException::class, 'exactly one active Gateway')
        ->and($events)
        ->toBe([]);
})->with([0, 2]);

it('publishes Metrics when the Metrics node is also the active Gateway', function (): void {
    [$metrics, $assignment] = metricsBaselineTopology(metricsIsGateway: true);
    $events = [];
    $baseline = metricsBaseline($events);

    $baseline->converge($metrics, $assignment);

    expect($events[array_key_last($events)])->toBe('publication:converge');
});

it('rolls completed runtime and exporter stages back in reverse order', function (
    string $failure,
    array $expected,
): void {
    [$metrics, $assignment] = metricsBaselineTopology();
    $events = [];
    $baseline = metricsBaseline($events, failure: $failure);

    expect(fn () => $baseline->converge($metrics, $assignment))
        ->toThrow(RuntimeException::class, "{$failure} failed")
        ->and($events)
        ->toBe($expected);
})->with([
    'runtime failure' => [
        'runtime:converge',
        ['exporters:converge', 'runtime:converge', 'exporters:remove'],
    ],
    'publication failure' => [
        'publication:converge',
        [
            'exporters:converge',
            'runtime:converge',
            'publication:converge',
            'runtime:remove',
            'exporters:remove',
        ],
    ],
]);

it('fails closed when convergence rollback does not complete', function (): void {
    [$metrics, $assignment] = metricsBaselineTopology();
    $events = [];
    $baseline = metricsBaseline(
        $events,
        failure: 'runtime:converge',
        rollbackFailure: 'exporters:remove',
    );

    try {
        $baseline->converge($metrics, $assignment);
        $exception = null;
    } catch (ResourceOperationException $caught) {
        $exception = $caught;
    }

    expect($exception)
        ->not
        ->toBeNull()
        ->and($exception?->errorCode)
        ->toBe('metrics.rollback_failed');
});

it('removes publication, exporters, and runtime in that order', function (): void {
    [$metrics, $assignment] = metricsBaselineTopology();
    $events = [];
    $report = new MetricsPublicationReport;
    $baseline = metricsBaseline($events, report: $report);

    $baseline->remove($metrics, $assignment, true);

    expect($events)
        ->toBe([
            'publication:remove',
            'exporters:remove',
            'runtime:remove:purge',
        ])
        ->and($report->take())
        ->toBe(MetricsPublicationCleanup::Cleaned);
});

it('removes node state before abandoning the publication when no single Gateway is active', function (
    int $gatewayCount,
): void {
    [$metrics, $assignment] = metricsBaselineTopology($gatewayCount);
    $events = [];
    $report = new MetricsPublicationReport;
    $baseline = metricsBaseline($events, report: $report);

    $baseline->remove($metrics, $assignment, false);

    expect($events)
        ->toBe([
            'exporters:remove',
            'runtime:remove',
            'publication:abandon',
        ])
        ->and($report->take())
        ->toBe(MetricsPublicationCleanup::Uncleaned);
})->with([0, 2]);

it('retracts only the Gateway-side publication when the Metrics node is unreachable', function (): void {
    [$metrics, $assignment] = metricsBaselineTopology();
    $events = [];
    $report = new MetricsPublicationReport;
    $baseline = metricsBaseline($events, report: $report);

    $baseline->removeUnreachable($metrics, $assignment);

    expect($events)
        ->toBe(['publication:retract'])
        ->and($report->take())
        ->toBe(MetricsPublicationCleanup::Cleaned);
});

it('does nothing and reports un-cleaned when no single Gateway is active for an unreachable Metrics node', function (
    int $gatewayCount,
): void {
    [$metrics, $assignment] = metricsBaselineTopology($gatewayCount);
    $events = [];
    $report = new MetricsPublicationReport;
    $baseline = metricsBaseline($events, report: $report);

    $baseline->removeUnreachable($metrics, $assignment);

    expect($events)
        ->toBe([])
        ->and($report->take())
        ->toBe(MetricsPublicationCleanup::Uncleaned);
})->with([0, 2]);

it('still removes the role and reports un-cleaned when abandoning the publication fails', function (): void {
    [$metrics, $assignment] = metricsBaselineTopology(gatewayCount: 0);
    $events = [];
    $report = new MetricsPublicationReport;
    $baseline = metricsBaseline($events, failure: 'publication:abandon', report: $report);

    $baseline->remove($metrics, $assignment, false);

    expect($events)
        ->toBe([
            'exporters:remove',
            'runtime:remove',
            'publication:abandon',
        ])
        ->and($report->take())
        ->toBe(MetricsPublicationCleanup::Uncleaned);
});

/** @return array{Node, NodeRole} */
function metricsBaselineTopology(int $gatewayCount = 1, bool $metricsIsGateway = false): array
{
    $metrics = Node::query()->create([
        'name' => 'metrics',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.3',
        'ssh_user' => 'orbit',
        'wireguard_address' => '10.44.0.3',
    ]);
    $assignment = $metrics
        ->roles()
        ->create([
            'role' => RoleName::Metrics,
            'status' => LifecycleStatus::Provisioning,
        ]);

    if ($metricsIsGateway) {
        $metrics
            ->roles()
            ->create([
                'role' => RoleName::Gateway,
                'status' => LifecycleStatus::Active,
            ]);

        return [$metrics, $assignment];
    }

    for ($index = 1; $index <= $gatewayCount; $index++) {
        $gateway = Node::query()->create([
            'name' => "gateway-{$index}",
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'public_ssh_host' => "192.0.2.{$index}",
            'ssh_user' => 'orbit',
            'wireguard_address' => "10.44.0.{$index}",
        ]);
        $gateway
            ->roles()
            ->create([
                'role' => RoleName::Gateway,
                'status' => LifecycleStatus::Active,
            ]);
    }

    return [$metrics, $assignment];
}

function metricsBaseline(
    array &$events,
    ?string $failure = null,
    ?string $rollbackFailure = null,
    ?MetricsPublicationReport $report = null,
): MetricsRoleBaseline {
    return new MetricsRoleBaseline(
        runtime: new MetricsBaselineRuntime($events, $failure, $rollbackFailure),
        exporters: new MetricsBaselineExporters($events, $failure, $rollbackFailure),
        publication: new MetricsBaselinePublication($events, $failure, $rollbackFailure),
        gateways: new MetricsGatewayResolver,
        report: $report ?? new MetricsPublicationReport,
    );
}

final class MetricsBaselineRuntime implements MetricsRuntimeLifecycle
{
    public function __construct(
        private array &$events,
        private ?string $failure,
        private ?string $rollbackFailure,
    ) {}

    public function converge(Node $node, NodeRole $assignment): void
    {
        $this->record('runtime:converge');
    }

    public function remove(Node $node, NodeRole $assignment, bool $purgeData): void
    {
        $this->record($purgeData ? 'runtime:remove:purge' : 'runtime:remove');
    }

    public function health(Node $node, string $service): bool
    {
        return true;
    }

    private function record(string $event): void
    {
        $this->events[] = $event;

        if ($this->failure === $event || $this->rollbackFailure === $event) {
            throw new RuntimeException("{$event} failed");
        }
    }
}

final class MetricsBaselineExporters implements MetricsExporterLifecycle
{
    public function __construct(
        private array &$events,
        private ?string $failure,
        private ?string $rollbackFailure,
    ) {}

    public function converge(Node $node, NodeRole $assignment): void
    {
        $this->record('exporters:converge');
    }

    public function remove(Node $node, NodeRole $assignment): void
    {
        $this->record('exporters:remove');
    }

    public function removeNode(Node $node, Node $metricsNode): void
    {
        $this->record('exporters:remove-node');
    }

    public function actual(Node $node): string
    {
        return 'active';
    }

    public function targets(Node $metricsNode): array
    {
        return [];
    }

    private function record(string $event): void
    {
        $this->events[] = $event;

        if ($this->failure === $event || $this->rollbackFailure === $event) {
            throw new RuntimeException("{$event} failed");
        }
    }
}

final class MetricsBaselinePublication implements MetricsPublicationManager
{
    public function __construct(
        private array &$events,
        private ?string $failure,
        private ?string $rollbackFailure,
    ) {}

    public function converge(Node $gateway, Node $metrics): void
    {
        $this->record('publication:converge');
    }

    public function remove(Node $gateway, Node $metrics): void
    {
        $this->record('publication:remove');
    }

    public function abandon(Node $metrics): void
    {
        $this->record('publication:abandon');
    }

    public function retract(Node $metrics): void
    {
        $this->record('publication:retract');
    }

    private function record(string $event): void
    {
        $this->events[] = $event;

        if ($this->failure === $event || $this->rollbackFailure === $event) {
            throw new RuntimeException("{$event} failed");
        }
    }
}
