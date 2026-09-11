<?php

declare(strict_types=1);

namespace App\Actions\AppInstances;

use App\Domain\AppInstances\Deployment\DeploymentConfig;
use App\Models\AppInstance;

final readonly class ShowAppInstanceDeploymentConfigAction
{
    public function __construct(private AppInstanceDeploymentConfigResolver $resolver) {}

    public function execute(AppInstance $instance): DeploymentConfig
    {
        return $this->resolver->resolve($instance->refresh());
    }
}
