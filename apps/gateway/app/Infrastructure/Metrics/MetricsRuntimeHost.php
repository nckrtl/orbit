<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Models\Node;

interface MetricsRuntimeHost
{
    public function snapshotConfiguration(
        Node $node,
        MetricsConfigurationBundle $configuration,
    ): MetricsConfigurationSnapshot;

    public function publishConfiguration(Node $node, MetricsConfigurationBundle $configuration): void;

    public function restoreConfiguration(Node $node, MetricsConfigurationSnapshot $snapshot): void;

    /** @param non-empty-list<MetricsContainerSpec> $specs */
    public function convergeContainers(Node $node, array $specs): void;

    /** @param non-empty-list<MetricsContainerSpec> $specs */
    public function removeContainers(Node $node, array $specs): void;

    public function removeConfiguration(Node $node): void;

    /** @param non-empty-list<MetricsContainerSpec> $specs */
    public function purgeVolumes(Node $node, array $specs): void;

    public function health(Node $node, MetricsService $service): bool;
}
