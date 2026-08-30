<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Domain\Metrics\MetricsCredentialManager;
use App\Domain\Metrics\MetricsExporterLifecycle;
use App\Domain\Metrics\MetricsRuntimeLifecycle;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\NodeRole;
use Throwable;

final readonly class NativeMetricsContainerRuntime implements MetricsRuntimeLifecycle
{
    public function __construct(
        private MetricsRuntimeHost $host,
        private MetricsRuntimeSpec $spec,
        private MetricsConfigurationRenderer $configurations,
        private MetricsCredentialManager $credentials,
        private MetricsExporterLifecycle $exporters,
    ) {}

    public function converge(Node $node, NodeRole $assignment): void
    {
        $password = $this->credentials->passwordForConvergence($node);
        $configuration = $this->configurations->render($this->exporters->targets($node), $password);
        $specs = $this->specs(
            $node,
            $assignment,
            $configuration->prometheusHash,
            $configuration->grafanaHash,
        );
        $snapshot = $this->host->snapshotConfiguration($node, $configuration);

        try {
            $this->host->publishConfiguration($node, $configuration);
            $this->host->convergeContainers($node, $specs);
            $this->credentials->verifyActive($node);
        } catch (Throwable $exception) {
            try {
                $this->host->restoreConfiguration($node, $snapshot);
            } catch (Throwable $rollback) {
                throw new ResourceOperationException(
                    'metrics.runtime_rollback_failed',
                    'Metrics runtime convergence failed and configuration recovery did not complete.',
                    502,
                    new ResourceOperationException(
                        'metrics.runtime_convergence_failed',
                        $exception->getMessage(),
                        502,
                        $rollback,
                    ),
                );
            }

            throw $exception;
        }
    }

    public function remove(Node $node, NodeRole $assignment, bool $purgeData): void
    {
        $specs = $this->specs($node, $assignment, 'removal', 'removal');

        $this->host->removeContainers($node, $specs);
        $this->host->removeConfiguration($node);

        if (! $purgeData) {
            return;
        }

        $this->host->purgeVolumes($node, $specs);
        $this->credentials->purge($node);
    }

    public function health(Node $node, string $service): bool
    {
        $metricsService = MetricsService::tryFrom($service);

        return $metricsService instanceof MetricsService && $this->host->health($node, $metricsService);
    }

    /**
     * Builds one specification per service, each against its own configuration.
     *
     * Each container is converged against the files it reads, so a target
     * change replaces Prometheus alone and a Grafana settings change replaces
     * Grafana alone.
     *
     * @return non-empty-list<MetricsContainerSpec>
     */
    private function specs(
        Node $node,
        NodeRole $assignment,
        string $prometheusHash,
        string $grafanaHash,
    ): array {
        $address = is_string($node->wireguard_address) ? $node->wireguard_address : '';

        return [
            $this->spec->for(MetricsService::Prometheus, $assignment->id, $address, $prometheusHash),
            $this->spec->for(MetricsService::Grafana, $assignment->id, $address, $grafanaHash),
        ];
    }
}
