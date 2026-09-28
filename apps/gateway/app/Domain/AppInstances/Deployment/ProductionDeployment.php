<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Deployment;

use App\Infrastructure\Processes\CommandResult;
use App\Models\Instance;

interface ProductionDeployment
{
    public function prepare(Instance $appInstance, string $branch): DeploymentRelease;

    public function executeStep(
        Instance $appInstance,
        DeploymentRelease $release,
        DeploymentStep $step,
        DeploymentRequest $request,
    ): CommandResult;

    public function activate(Instance $appInstance, DeploymentRelease $release): DeploymentRelease;

    public function selected(Instance $appInstance): ?DeploymentRelease;

    public function retained(Instance $appInstance, string $name): DeploymentRelease;

    public function releases(Instance $appInstance): DeploymentReleaseState;
}
