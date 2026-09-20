<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Domain\AppInstances\AppInstanceState;
use App\Domain\AppInstances\ProductionPhpRuntimeIdentity;
use App\Domain\Metrics\MetricsExporterProjection;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Route;

final readonly class ServiceMetricsProjection
{
    public function __construct(private MetricsExporterProjection $exporters) {}

    public function enabled(Node $node): bool
    {
        $assignments = NodeRole::query()
            ->where('role', RoleName::Metrics)
            ->whereIn('status', [LifecycleStatus::Active, LifecycleStatus::Provisioning])
            ->with('node')->limit(2)->get();

        return $assignments->count() === 1
            && $this->forNode($assignments->sole()->node, $node)->fpm;
    }

    public function forNode(Node $metricsNode, Node $node, bool $enabled = true): ServiceMetricsNode
    {
        $item = $this->exporters->forNode($metricsNode, $node);
        $selected = $enabled && ($item?->selection->selected ?? false);
        $node = $item->node ?? $node;
        $node->loadMissing('roles');
        $roles = $node->roles->filter(static fn (NodeRole $role): bool => in_array(
            $role->status, [LifecycleStatus::Active, LifecycleStatus::Provisioning], true,
        ))->pluck('role');
        $caddy = $selected && $roles->contains(RoleName::Ingress);
        $fpm = $selected && $roles->contains(RoleName::AppProd);
        $instances = AppInstance::query()->where('node_id', $node->id)
            ->where('environment', 'production')->where('status', AppInstanceState::Active)
            ->whereNotNull('production_php_service')->whereNotNull('selected_php_version')
            ->with(['app', 'node'])->orderBy('id')->get()->all();
        foreach ($instances as $instance) {
            ProductionPhpRuntimeIdentity::from($instance);
        }
        $hosts = $caddy ? Route::query()
            ->where('publication', 'public')->where('status', 'active')
            ->whereHas('cluster.ingressAssignment', static fn ($query) => $query->where('node_id', $node->id))
            ->orderBy('domain')->pluck('domain')->all() : [];

        return new ServiceMetricsNode($node, $caddy && $hosts !== [], $fpm, $instances, $hosts);
    }

    /** @return list<ServiceMetricsNode> */
    public function forFleet(Node $metricsNode, bool $enabled = true): array
    {
        return array_map(
            fn ($item): ServiceMetricsNode => $this->forNode($metricsNode, $item->node, $enabled),
            $this->exporters->for($metricsNode),
        );
    }
}
