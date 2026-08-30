<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

/**
 * The generated Metrics files and the hash each container is converged against.
 *
 * The two hashes are separate on purpose. One union hash makes every fleet
 * change replace both containers, so adding a node drops every live Grafana
 * session and a dashboard edit restarts Prometheus. Each hash therefore covers
 * only the files its own service reads at start.
 */
final readonly class MetricsConfigurationBundle
{
    /** @param non-empty-list<MetricsGeneratedFile> $files */
    public function __construct(
        public array $files,
        public string $prometheusHash,
        public string $grafanaHash,
    ) {}
}
