<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Data\Instances\DeploymentStepData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Instances\Deployment\DeploymentStep;
use App\Domain\Instances\Deployment\InstanceDeployStepStore;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Models\Instance;

final readonly class CreateInstanceDeployStepAction
{
    public function __construct(
        private InstanceDeploymentConfigResolver $resolver,
        private InstanceDeployStepStore $steps,
        private InstanceEnvironmentOperationLock $operations,
        private ?RecordEventBroadcaster $broadcaster = null,
    ) {}

    public function execute(
        Instance $instance,
        DeploymentStep $step,
        ?string $before,
        ?string $after,
    ): DeploymentStep {
        $result = $this->operations->run([$instance->id], function () use ($instance, $step, $before, $after): DeploymentStep {
            $locked = Instance::query()->lockForUpdate()->findOrFail($instance->id);
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
