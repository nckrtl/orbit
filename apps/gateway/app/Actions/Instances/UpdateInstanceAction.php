<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Data\Instances\InstanceData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Models\Instance;

final readonly class UpdateInstanceAction
{
    public function __construct(
        private InstanceDeploymentConfigResolver $resolver,
        private InstanceEnvironmentOperationLock $operations,
        private ?RecordEventBroadcaster $broadcaster = null,
    ) {}

    public function execute(Instance $instance, string $branch): Instance
    {
        $result = $this->operations->run([$instance->id], function () use ($instance, $branch): Instance {
            $locked = Instance::query()->lockForUpdate()->findOrFail($instance->id);
            $this->resolver->assertAvailable($locked);
            $locked->update(['deployment_branch' => $branch]);

            return $locked->refresh();
        });

        ($this->broadcaster ?? app(RecordEventBroadcaster::class))->broadcast(
            RecordEventType::InstanceUpdated,
            $result->id,
            InstanceData::fromModel($result)->toArray(),
        );

        return $result;
    }
}
