<?php

declare(strict_types=1);

namespace App\Actions\AppInstances;

use App\Data\AppInstances\AppInstanceData;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentOperationLock;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Models\AppInstance;

final readonly class UpdateAppInstanceAction
{
    public function __construct(
        private AppInstanceDeploymentConfigResolver $resolver,
        private AppInstanceEnvironmentOperationLock $operations,
        private ?RecordEventBroadcaster $broadcaster = null,
    ) {}

    public function execute(AppInstance $instance, string $branch): AppInstance
    {
        $result = $this->operations->run([$instance->id], function () use ($instance, $branch): AppInstance {
            $locked = AppInstance::query()->lockForUpdate()->findOrFail($instance->id);
            $this->resolver->assertAvailable($locked);
            $locked->update(['deployment_branch' => $branch]);

            return $locked->refresh();
        });

        ($this->broadcaster ?? app(RecordEventBroadcaster::class))->broadcast(
            RecordEventType::InstanceUpdated,
            $result->id,
            AppInstanceData::fromModel($result)->toArray(),
        );

        return $result;
    }
}
