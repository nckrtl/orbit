<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Domain\Instances\Deployment\DeploymentStep;
use App\Domain\Instances\Deployment\InstanceDeployStepStore;
use App\Models\Instance;

final readonly class ListInstanceDeployStepsAction
{
    public function __construct(
        private InstanceDeploymentConfigResolver $resolver,
        private InstanceDeployStepStore $steps,
    ) {}

    /** @return list<DeploymentStep> */
    public function execute(Instance $instance): array
    {
        $this->resolver->assertAvailable($instance->refresh());

        return $this->steps->ordered($instance);
    }
}
