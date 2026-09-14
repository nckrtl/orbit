<?php

declare(strict_types=1);

namespace App\Actions\AppInstances;

use App\Domain\AppInstances\Deployment\AppInstanceDeployStepStore;
use App\Domain\AppInstances\Deployment\DeploymentStep;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentOperationLock;
use App\Models\AppInstance;

final readonly class CreateAppInstanceDeployStepAction
{
    public function __construct(
        private AppInstanceDeploymentConfigResolver $resolver,
        private AppInstanceDeployStepStore $steps,
        private AppInstanceEnvironmentOperationLock $operations,
    ) {}

    public function execute(
        AppInstance $instance,
        DeploymentStep $step,
        ?string $before,
        ?string $after,
    ): DeploymentStep {
        return $this->operations->run([$instance->id], function () use ($instance, $step, $before, $after): DeploymentStep {
            $locked = AppInstance::query()->lockForUpdate()->findOrFail($instance->id);
            $this->resolver->assertAvailable($locked);

            return $this->steps->create($locked, $step, $before, $after);
        });
    }
}
