<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Domain\Instances\Deployment\DeploymentReleaseState;
use App\Domain\Instances\Deployment\ProductionDeployment;
use App\Models\Instance;

final readonly class ListInstanceReleasesAction
{
    public function __construct(private ProductionDeployment $deployment) {}

    public function execute(Instance $instance): DeploymentReleaseState
    {
        return $this->deployment->releases($instance->refresh());
    }
}
