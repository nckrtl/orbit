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
        $instance->refresh();
        // A development default deploys in its checkout and has no releases.
        if ($instance->placedOnAppDev() && $instance->name === 'default') {
            return new DeploymentReleaseState([], null);
        }

        return $this->deployment->releases($instance);
    }
}
