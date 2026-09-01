<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Domain\Metrics\ExporterPreferenceRepository;
use App\Domain\Metrics\ExporterSelector;
use App\Domain\Metrics\MetricsExporterProjection;
use App\Domain\Metrics\MetricsExporterProjectionItem;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use App\Models\NodeRole;

final readonly class NativeMetricsExporterProjection implements MetricsExporterProjection
{
    public function __construct(
        private ExporterSelector $selector,
        private ExporterPreferenceRepository $preferences,
    ) {}

    public function for(Node $metricsNode): array
    {
        $items = [];

        foreach (Node::query()
            ->with('roles')
            ->where('status', LifecycleStatus::Active->value)
            ->orderBy('id')
            ->get() as $node) {
            $roles = array_values(
                $node
                    ->roles
                    ->filter(static fn (NodeRole $role): bool => in_array(
                        $role->status,
                        [LifecycleStatus::Provisioning, LifecycleStatus::Active],
                        strict: true,
                    ))
                    ->map(static fn (NodeRole $role): RoleName => $role->role)
                    ->all(),
            );
            $items[] = new MetricsExporterProjectionItem(
                $node,
                $this->selector->select(
                    $roles,
                    $this->preferences->get($node->id),
                    $node->is($metricsNode),
                ),
            );
        }

        return $items;
    }
}
