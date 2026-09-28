<?php

declare(strict_types=1);

namespace App\Actions\AppInstances;

use App\Domain\AppInstances\Deployment\DeploymentReleaseState;
use App\Domain\AppInstances\Deployment\ProductionDeployment;
use App\Models\Instance;

final readonly class ListAppInstanceReleasesAction
{
    public function __construct(private ProductionDeployment $deployment) {}

    public function execute(Instance $appInstance): DeploymentReleaseState
    {
        return $this->deployment->releases($appInstance->refresh());
    }
}
