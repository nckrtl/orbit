<?php

declare(strict_types=1);

namespace App\Actions\AppInstances;

use App\Data\AppInstances\DeploymentStepData;
use App\Domain\AppInstances\Deployment\AppInstanceDeployStepStore;
use App\Domain\AppInstances\Deployment\DeploymentStep;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentOperationLock;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Models\AppInstance;

final readonly class CreateAppInstanceDeployStepAction
{
    public function __construct(
        private AppInstanceDeploymentConfigResolver $resolver,
        private AppInstanceDeployStepStore $steps,
        private AppInstanceEnvironmentOperationLock $operations,
        private ?RecordEventBroadcaster $broadcaster = null,
    ) {}

    public function execute(
        AppInstance $instance,
        DeploymentStep $step,
        ?string $before,
        ?string $after,
    ): DeploymentStep {
        $result = $this->operations->run([$instance->id], function () use ($instance, $step, $before, $after): DeploymentStep {
            $locked = AppInstance::query()->lockForUpdate()->findOrFail($instance->id);
            $this->resolver->assertAvailable($locked);

            return $this->steps->create($locked, $step, $before, $after);
        });

        ($this->broadcaster ?? app(RecordEventBroadcaster::class))->broadcast(
            RecordEventType::DeployStepCreated,
            $result->name,
            DeploymentStepData::fromDomain($result)->toArray(),
        );

        return $result;
    }
}
