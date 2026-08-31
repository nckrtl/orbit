<?php

declare(strict_types=1);

use App\Data\Metrics\MetricsCredentialsData;
use App\Domain\Metrics\MetricsCredentialManager;
use App\Domain\Metrics\MetricsExporterLifecycle;
use App\Infrastructure\Metrics\GrafanaConfigRenderer;
use App\Infrastructure\Metrics\MetricsConfigurationBundle;
use App\Infrastructure\Metrics\MetricsConfigurationRenderer;
use App\Infrastructure\Metrics\MetricsConfigurationSnapshot;
use App\Infrastructure\Metrics\MetricsContainerSpec;
use App\Infrastructure\Metrics\MetricsRuntimeHost;
use App\Infrastructure\Metrics\MetricsRuntimeSpec;
use App\Infrastructure\Metrics\MetricsService;
use App\Infrastructure\Metrics\NativeMetricsContainerRuntime;
use App\Models\Node;
use App\Models\NodeRole;

describe(NativeMetricsContainerRuntime::class, function (): void {
    it('publishes validated configuration and converges both owned containers', function (): void {
        $host = new MetricsRuntimeHostFake;
        $credentials = new MetricsCredentialManagerFake;
        $exporters = new MetricsExporterLifecycleFake;
        $runtime = metricsContainerRuntime($host, $credentials, $exporters);
        [$node, $assignment] = metricsRuntimeModels();

        $runtime->converge($node, $assignment);

        expect($host->events)
            ->toBe([
                'snapshot-configuration',
                'publish-configuration',
                'converge:prometheus,grafana',
            ])
            ->and($host->specs)
            ->toHaveCount(2)
            ->and($host->specs[0]->service)
            ->toBe(MetricsService::Prometheus)
            ->and($host->specs[1]->service)
            ->toBe(MetricsService::Grafana)
            ->and($credentials->verified)
            ->toBe([$node->id]);
    });

    it('moves only the Prometheus spec hash when the scrape targets change', function (): void {
        $host = new MetricsRuntimeHostFake;
        $exporters = new MetricsExporterLifecycleFake;
        $runtime = metricsContainerRuntime($host, new MetricsCredentialManagerFake, $exporters);
        [$node, $assignment] = metricsRuntimeModels();

        $runtime->converge($node, $assignment);
        $before = $host->specs;
        $exporters->targets[] = ['name' => 'app-prod', 'address' => '10.44.0.4'];
        $runtime->converge($node, $assignment);

        expect($host->specs[0]->specHash)
            ->not
            ->toBe($before[0]->specHash)
            ->and($host->specs[1]->specHash)
            ->toBe($before[1]->specHash);
    });

    it('gives each container the hash of the configuration that container reads', function (): void {
        $host = new MetricsRuntimeHostFake;
        $exporters = new MetricsExporterLifecycleFake;
        $runtime = metricsContainerRuntime($host, new MetricsCredentialManagerFake, $exporters);
        [$node, $assignment] = metricsRuntimeModels();

        $runtime->converge($node, $assignment);
        $configuration = new MetricsConfigurationRenderer()->render($exporters->targets, 'runtime-admin-password');
        $spec = new MetricsRuntimeSpec;

        expect($configuration->prometheusHash)
            ->not
            ->toBe($configuration->grafanaHash)
            ->and($host->specs[0]->specHash)
            ->toBe(
                $spec->for(
                    MetricsService::Prometheus,
                    $assignment->id,
                    '10.44.0.3',
                    $configuration->prometheusHash,
                )->specHash,
            )
            ->and($host->specs[1]->specHash)
            ->toBe(
                $spec->for(
                    MetricsService::Grafana,
                    $assignment->id,
                    '10.44.0.3',
                    $configuration->grafanaHash,
                )->specHash,
            );
    });

    it('restores the exact configuration snapshot when container convergence fails', function (): void {
        $host = new MetricsRuntimeHostFake;
        $host->convergenceFailure = new RuntimeException('container failure');
        $runtime = metricsContainerRuntime(
            $host,
            new MetricsCredentialManagerFake,
            new MetricsExporterLifecycleFake,
        );
        [$node, $assignment] = metricsRuntimeModels();

        expect(fn () => $runtime->converge($node, $assignment))
            ->toThrow(RuntimeException::class, 'container failure')
            ->and($host->events)
            ->toBe([
                'snapshot-configuration',
                'publish-configuration',
                'converge:prometheus,grafana',
                'restore-configuration',
            ]);
    });

    it('preserves volumes and credentials on normal removal', function (): void {
        $host = new MetricsRuntimeHostFake;
        $credentials = new MetricsCredentialManagerFake;
        $runtime = metricsContainerRuntime($host, $credentials, new MetricsExporterLifecycleFake);
        [$node, $assignment] = metricsRuntimeModels();

        $runtime->remove($node, $assignment, false);

        expect($host->events)
            ->toBe(['remove-containers', 'remove-configuration'])
            ->and($credentials->purged)
            ->toBe([]);
    });

    it('purges only proven runtime volumes and credential settings when requested', function (): void {
        $host = new MetricsRuntimeHostFake;
        $credentials = new MetricsCredentialManagerFake;
        $runtime = metricsContainerRuntime($host, $credentials, new MetricsExporterLifecycleFake);
        [$node, $assignment] = metricsRuntimeModels();

        $runtime->remove($node, $assignment, true);

        expect($host->events)
            ->toBe(['remove-containers', 'remove-configuration', 'purge-volumes'])
            ->and($credentials->purged)
            ->toBe([$node->id]);
    });
});

function metricsContainerRuntime(
    MetricsRuntimeHost $host,
    MetricsCredentialManager $credentials,
    MetricsExporterLifecycle $exporters,
): NativeMetricsContainerRuntime {
    return new NativeMetricsContainerRuntime(
        host: $host,
        spec: new MetricsRuntimeSpec,
        configurations: new MetricsConfigurationRenderer,
        credentials: $credentials,
        exporters: $exporters,
    );
}

/** @return array{Node, NodeRole} */
function metricsRuntimeModels(): array
{
    $node = new Node([
        'name' => 'metrics-runtime',
        'wireguard_ip' => '10.44.0.3',
    ]);
    $node->id = 3;
    $assignment = new NodeRole;
    $assignment->id = 41;
    $assignment->node_id = $node->id;

    return [$node, $assignment];
}

final class MetricsRuntimeHostFake implements MetricsRuntimeHost
{
    /** @var list<string> */
    public array $events = [];

    /** @var list<MetricsContainerSpec> */
    public array $specs = [];

    public ?RuntimeException $convergenceFailure = null;

    public function snapshotConfiguration(
        Node $node,
        MetricsConfigurationBundle $configuration,
    ): MetricsConfigurationSnapshot {
        $this->events[] = 'snapshot-configuration';

        return new MetricsConfigurationSnapshot(directoryExisted: true, files: []);
    }

    public function publishConfiguration(Node $node, MetricsConfigurationBundle $configuration): void
    {
        $this->events[] = 'publish-configuration';
    }

    public function restoreConfiguration(Node $node, MetricsConfigurationSnapshot $snapshot): void
    {
        expect($snapshot->files)->toBe([]);
        $this->events[] = 'restore-configuration';
    }

    public function convergeContainers(Node $node, array $specs): void
    {
        $this->specs = $specs;
        $this->events[] =
            'converge:'
            .implode(',', array_map(
                static fn (MetricsContainerSpec $spec): string => $spec->service->value,
                $specs,
            ));

        if ($this->convergenceFailure instanceof RuntimeException) {
            throw $this->convergenceFailure;
        }
    }

    public function removeContainers(Node $node, array $specs): void
    {
        $this->events[] = 'remove-containers';
    }

    public function removeConfiguration(Node $node): void
    {
        $this->events[] = 'remove-configuration';
    }

    public function purgeVolumes(Node $node, array $specs): void
    {
        $this->events[] = 'purge-volumes';
    }

    public function health(Node $node, MetricsService $service): bool
    {
        return true;
    }
}

final class MetricsCredentialManagerFake implements MetricsCredentialManager
{
    /** @var list<int> */
    public array $verified = [];

    /** @var list<int> */
    public array $purged = [];

    public function passwordForConvergence(Node $node): string
    {
        return 'runtime-admin-password';
    }

    public function verifyActive(Node $node): void
    {
        $this->verified[] = $node->id;
    }

    public function purge(Node $node): void
    {
        $this->purged[] = $node->id;
    }

    public function credentials(): MetricsCredentialsData
    {
        throw new RuntimeException('Not used by this test.');
    }

    public function reset(): MetricsCredentialsData
    {
        throw new RuntimeException('Not used by this test.');
    }
}

final class MetricsExporterLifecycleFake implements MetricsExporterLifecycle
{
    /** @var list<array{name: string, address: string}> */
    public array $targets = [
        ['name' => 'gateway', 'address' => '10.44.0.1'],
        ['name' => 'metrics-runtime', 'address' => '10.44.0.3'],
    ];

    public function converge(Node $node, NodeRole $assignment): void {}

    public function remove(Node $node, NodeRole $assignment): void {}

    public function removeNode(Node $node, Node $metricsNode): void {}

    public function actual(Node $node): string
    {
        return 'active';
    }

    public function targets(Node $metricsNode): array
    {
        return $this->targets;
    }
}
