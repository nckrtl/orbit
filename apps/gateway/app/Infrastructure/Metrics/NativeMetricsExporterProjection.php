<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Domain\Metrics\ExporterPreferenceRepository;
use App\Domain\Metrics\ExporterSelectionReason;
use App\Domain\Metrics\ExporterSelector;
use App\Domain\Metrics\MetricsExporterProjection;
use App\Domain\Metrics\MetricsExporterProjectionItem;
use App\Domain\Nodes\ManagedNodeEligibility;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use App\Models\NodeRole;

final readonly class NativeMetricsExporterProjection implements MetricsExporterProjection
{
    public function __construct(
        private ExporterSelector $selector,
        private ExporterPreferenceRepository $preferences,
        private ManagedNodeEligibility $eligibility = new ManagedNodeEligibility,
    ) {}

    public function for(Node $metricsNode): array
    {
        $items = [];

        foreach (Node::query()
            ->with('roles')
            ->where('status', LifecycleStatus::Active->value)
            ->orderBy('id')
            ->get() as $node) {
            $item = $this->item($metricsNode, $node);

            if ($item instanceof MetricsExporterProjectionItem) {
                $items[] = $item;
            }
        }

        return $items;
    }

    public function forNode(Node $metricsNode, Node $node): ?MetricsExporterProjectionItem
    {
        if (! $node->exists) {
            return null;
        }

        $projectedNode = Node::query()
            ->with('roles')
            ->whereKey($node->getKey())
            ->where('status', LifecycleStatus::Active->value)
            ->first();

        if (! $projectedNode instanceof Node) {
            return null;
        }

        return $this->item($metricsNode, $projectedNode);
    }

    private function item(Node $metricsNode, Node $node): ?MetricsExporterProjectionItem
    {
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

        $selection = $this->selector->select(
            $roles,
            $this->preferences->get($node->id),
            $node->is($metricsNode),
            $this->eligibility->allows($node),
        );

        return $selection->reason === ExporterSelectionReason::Ineligible
            ? null
            : new MetricsExporterProjectionItem($node, $selection);
    }
}
