<?php

declare(strict_types=1);

namespace App\Actions\AppInstances;

use App\Domain\AppInstances\Deployment\AppInstanceDeployStepStore;
use App\Domain\AppInstances\Deployment\DeploymentStep;
use App\Models\AppInstance;

final readonly class ListAppInstanceDeployStepsAction
{
    public function __construct(
        private AppInstanceDeploymentConfigResolver $resolver,
        private AppInstanceDeployStepStore $steps,
    ) {}

    /** @return list<DeploymentStep> */
    public function execute(AppInstance $instance): array
    {
        $this->resolver->assertAvailable($instance->refresh());

        return $this->steps->ordered($instance);
    }
}
