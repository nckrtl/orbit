<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use Illuminate\Database\Eloquent\Builder;

/**
 * Finds the one active Gateway a Metrics publication belongs to.
 *
 * Publishing needs a single Gateway to bind the route, the certificate and the
 * firewall rule to. Removal only needs to know whether that Gateway is there,
 * so the two callers share one query and cannot drift apart.
 */
final readonly class MetricsGatewayResolver
{
    public function find(): ?Node
    {
        $gateways = Node::query()
            ->where('status', LifecycleStatus::Active->value)
            ->whereHas('roles', static fn (Builder $query): Builder => $query
                ->where('role', RoleName::Gateway->value)
                ->where('status', LifecycleStatus::Active->value))
            ->limit(2)
            ->get();

        return $gateways->count() === 1 ? $gateways->sole() : null;
    }

    public function resolve(): Node
    {
        $gateway = $this->find();

        if (! $gateway instanceof Node) {
            throw new ResourceOperationException(
                'metrics.gateway_ambiguous',
                'Metrics publication requires exactly one active Gateway.',
            );
        }

        return $gateway;
    }
}
