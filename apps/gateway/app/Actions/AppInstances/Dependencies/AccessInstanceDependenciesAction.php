<?php

declare(strict_types=1);

namespace App\Actions\AppInstances\Dependencies;

use App\Data\AppInstances\Dependencies\InstanceDependencyInventoryData;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\AppInstances\Dependencies\DependencyEcosystem;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentOperationLock;
use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Domain\Shared\ResourceOperationException;
use App\Models\AppInstance;
use App\Models\Node;
use Illuminate\Support\Facades\DB;

final readonly class AccessInstanceDependenciesAction
{
    public function __construct(
        private AppInstanceEnvironmentOperationLock $operations,
        private NodeAccessAuthorizer $authorizer,
        private ScanInstanceDependenciesAction $scan,
        private ReadInstanceDependencyScanAction $read,
    ) {}

    public function execute(AppInstance $instance, Node $consumer, bool $scan): InstanceDependencyInventoryData
    {
        try {
            return $this->operations->run([$instance->id], function () use ($instance, $consumer, $scan): InstanceDependencyInventoryData {
                $current = $this->target($instance->id, $consumer);
                $result = $scan ? $this->scan->execute($current) : null;

                return DB::transaction(function () use ($instance, $consumer, $result): InstanceDependencyInventoryData {
                    $this->target($instance->id, $consumer);

                    return InstanceDependencyInventoryData::fromResults(
                        $instance->id,
                        $result === null ? $this->read->execute($instance->id, DependencyEcosystem::Composer) : $result->composer,
                        $result === null ? $this->read->execute($instance->id, DependencyEcosystem::Npm) : $result->javascript,
                    );
                });
            });
        } catch (ResourceOperationException $exception) {
            if ($exception->errorCode === 'env.operation_busy') {
                throw new ResourceOperationException('dependencies.operation_busy', 'The instance has another operation in progress.', 409);
            }

            throw $exception;
        }
    }

    private function target(int $instanceId, Node $consumer): AppInstance
    {
        $current = AppInstance::query()->with('node')->findOrFail($instanceId);
        if (! $this->authorizer->allows($consumer, $current->node)) {
            throw new ResourceOperationException('node_access.required', 'Node access is required.', 403);
        }
        if ($current->status !== AppInstanceState::Active || $current->migration_required || $current->removalMember()->exists()) {
            throw new ResourceOperationException('dependencies.instance_unavailable', 'The instance is unavailable for dependency inventory.', 409);
        }

        return $current;
    }
}
