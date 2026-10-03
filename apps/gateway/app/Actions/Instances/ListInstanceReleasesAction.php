<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Domain\Instances\Deployment\DeploymentReleaseState;
use App\Domain\Instances\Deployment\DevelopmentDeployment;
use App\Domain\Instances\Deployment\ProductionDeployment;
use App\Models\Instance;

final readonly class ListInstanceReleasesAction
{
    public function __construct(private ProductionDeployment $deployment, private ?DevelopmentDeployment $development = null) {}

    public function execute(Instance $instance): DeploymentReleaseState
    {
        $instance->refresh();
        if ($instance->placedOnAppDev() && $instance->name === 'default') {
            return $instance->development_release_layout
                ? ($this->development ?? app(DevelopmentDeployment::class))->releases($instance)
                : new DeploymentReleaseState([], null);
        }

        return $this->deployment->releases($instance);
    }
}
