<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Domain\Firewall\FirewallInspectionShape;
use App\Domain\Firewall\FirewallInspectionTarget;
use App\Domain\Metrics\MetricsExporterProjection;
use App\Domain\Metrics\MetricsFirewallExpectationProvider;
use App\Domain\Metrics\MetricsGatewayResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Firewall\NodeFirewallRuleCatalog;
use App\Infrastructure\Firewall\UfwManagedRule;
use App\Models\Node;
use App\Models\NodeRole;
use Illuminate\Database\Eloquent\Builder;

final readonly class NativeMetricsFirewallExpectationProvider implements MetricsFirewallExpectationProvider
{
    public function __construct(
        private MetricsExporterProjection $exporters,
        private MetricsGatewayResolver $gateways,
        private NodeFirewallRuleCatalog $catalog,
    ) {}

    public function for(Node $node): array
    {
        $assignments = NodeRole::query()
            ->where('role', RoleName::Metrics->value)
            ->where('status', LifecycleStatus::Active->value)
            ->whereHas('node', static fn (Builder $query): Builder => $query->where(
                'status',
                LifecycleStatus::Active->value,
            ))
            ->with('node')
            ->limit(2)
            ->get();

        if ($assignments->count() !== 1) {
            return [];
        }

        $metricsNode = $assignments->sole()->node;
        $targets = [];

        foreach ($this->exporters->for($metricsNode) as $item) {
            if ($item->node->is($node) && $item->selection->selected) {
                $targets[] = $this->target(
                    $node,
                    $this->catalog->metricsExporter($node, $metricsNode),
                    MetricsFootprint::ExporterFirewallComment,
                    'Metrics node exporter',
                );

                break;
            }
        }

        $gateway = $this->gateways->find();

        if ($node->is($metricsNode) && $gateway instanceof Node) {
            $targets[] = $this->target(
                $node,
                $this->catalog->metricsGrafanaUpstream($metricsNode, (string) $gateway->wireguard_ip),
                MetricsFootprint::PublicationFirewallComment,
                'Metrics Grafana upstream',
            );
        }

        return $targets;
    }

    private function target(
        Node $node,
        UfwManagedRule $rule,
        string $resourceId,
        string $resourceName,
    ): FirewallInspectionTarget {
        $shape = $rule->shape;

        return new FirewallInspectionTarget(
            node: $node,
            shape: new FirewallInspectionShape(
                comment: $shape->comment,
                action: $shape->action,
                direction: $shape->direction,
                source: $shape->source,
                destination: $shape->destination,
                port: $shape->port,
                protocol: $shape->protocol,
                inInterface: $shape->inInterface,
                outInterface: $shape->outInterface,
                family: $shape->family,
            ),
            resourceId: $resourceId,
            resourceName: $resourceName,
        );
    }
}
