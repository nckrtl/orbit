<?php

declare(strict_types=1);

namespace App\Actions\Instances\Dependencies;

use App\Data\Instances\Dependencies\InstanceDependencyInventoryData;
use App\Data\Instances\Dependencies\InstanceDependencyUpdateData;
use App\Domain\Instances\Dependencies\DependencyEcosystem;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Instances\InstanceState;
use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Node;
use Illuminate\Support\Facades\DB;

final readonly class AccessInstanceDependenciesAction
{
    public function __construct(
        private InstanceEnvironmentOperationLock $operations,
        private NodeAccessAuthorizer $authorizer,
        private ScanInstanceDependenciesAction $scan,
        private ReadInstanceDependencyScanAction $read,
        private UpdateInstanceDependenciesAction $updates,
    ) {}

    public function execute(Instance $instance, Node $consumer, bool $scan): InstanceDependencyInventoryData
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

    public function update(Instance $instance, Node $consumer): InstanceDependencyUpdateData
    {
        try {
            return $this->operations->run([$instance->id], function () use ($instance, $consumer): InstanceDependencyUpdateData {
                $current = $this->target($instance->id, $consumer);
                $result = $this->updates->execute($current);

                return DB::transaction(function () use ($instance, $consumer, $result): InstanceDependencyUpdateData {
                    $this->target($instance->id, $consumer);

                    return InstanceDependencyUpdateData::fromResult($result);
                });
            });
        } catch (ResourceOperationException $exception) {
            if ($exception->errorCode === 'env.operation_busy') {
                throw new ResourceOperationException('dependencies.operation_busy', 'The instance has another operation in progress.', 409);
            }

            throw $exception;
        }
    }

    private function target(int $instanceId, Node $consumer): Instance
    {
        $current = Instance::query()->with('node')->findOrFail($instanceId);
        if (! $this->authorizer->allows($consumer, $current->node)) {
            throw new ResourceOperationException('node_access.required', 'Node access is required.', 403);
        }
        if ($current->status !== InstanceState::Active || $current->removalMember()->exists()) {
            throw new ResourceOperationException('dependencies.instance_unavailable', 'The instance is unavailable for dependency inventory.', 409);
        }

        return $current;
    }
}
