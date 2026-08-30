<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Actions\Nodes\AddNodeRoleAction;
use App\Actions\Nodes\RemoveNodeRoleAction;
use App\Data\Metrics\MetricsMutationData;
use App\Domain\Metrics\ExporterPreference;
use App\Domain\Metrics\ExporterPreferenceRepository;
use App\Domain\Metrics\MetricsFleetReconciler;
use App\Domain\Metrics\MetricsGatewayResolver;
use App\Domain\Metrics\MetricsPublicationCleanup;
use App\Domain\Metrics\MetricsRoleManager;
use App\Domain\Nodes\RoleAssignmentException;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;

final readonly class NativeMetricsRoleManager implements MetricsRoleManager
{
    public function __construct(
        private AddNodeRoleAction $add,
        private RemoveNodeRoleAction $remove,
        private ExporterPreferenceRepository $preferences,
        private MetricsFleetReconciler $fleet,
        private MetricsGatewayResolver $gateways,
    ) {}

    public function enable(int $nodeId): MetricsMutationData
    {
        if (Node::query()->whereHas('roles', static fn ($query) => $query->where(
            'role',
            RoleName::Metrics->value,
        ))->exists()) {
            throw new RoleAssignmentException(
                'The metrics role is already assigned; remove it before enabling it on another node.',
            );
        }
        $node = Node::query()->findOrFail($nodeId);
        $result = $this->add->execute($node, RoleName::Metrics, true);

        return new MetricsMutationData($nodeId, $result['assignment']->status->value);
    }

    public function remove(bool $force, bool $purge): MetricsMutationData
    {
        $nodes = Node::query()
            ->whereHas('roles', static fn ($q) => $q->where('role', RoleName::Metrics->value))
            ->with('roles')
            ->limit(2)
            ->get();

        if ($nodes->isEmpty()) {
            throw new RoleAssignmentException('Metrics is not assigned.');
        }

        if ($nodes->count() !== 1) {
            throw new RoleAssignmentException('Metrics role assignment drift detected.');
        }

        $node = $nodes->sole();
        // The baseline can only clean the Gateway side of the publication
        // while one active Gateway exists. Reading that here, against the
        // same resolver, reports what the removal below was able to do.
        $publication = $this->gateways->find() instanceof Node
            ? MetricsPublicationCleanup::Cleaned
            : MetricsPublicationCleanup::Uncleaned;
        $this->remove->execute($node, RoleName::Metrics, $force, $purge);

        return new MetricsMutationData($node->id, 'removed', $publication);
    }

    public function enableExporter(int $nodeId): MetricsMutationData
    {
        $this->activeNode($nodeId);
        $this->preferences->put($nodeId, ExporterPreference::Enabled);
        $this->fleet->reconcile();

        return new MetricsMutationData($nodeId, 'enabled');
    }

    public function disableExporter(int $nodeId): MetricsMutationData
    {
        $node = $this->activeNode($nodeId);
        if ($node->roles()->where('role', RoleName::Metrics->value)->exists()) {
            throw new RoleAssignmentException('The metrics node exporter cannot be disabled.');
        }
        $this->preferences->put($nodeId, ExporterPreference::Disabled);
        $this->fleet->reconcile();

        return new MetricsMutationData($nodeId, 'disabled');
    }

    private function activeNode(int $nodeId): Node
    {
        $node = Node::query()->findOrFail($nodeId);

        if ($node->status !== LifecycleStatus::Active) {
            throw new ResourceOperationException(
                'metrics.exporter_node_inactive',
                'Metrics exporters require an active node.',
                409,
            );
        }

        return $node;
    }
}
