<?php

declare(strict_types=1);

namespace App\Domain\AppInstances\Deployment;

use App\Infrastructure\Processes\CommandResult;
use App\Models\AppInstance;

interface ProductionDeployment
{
    public function prepare(AppInstance $appInstance, string $branch): DeploymentRelease;

    public function executeStep(
        AppInstance $appInstance,
        DeploymentRelease $release,
        DeploymentStep $step,
        DeploymentRequest $request,
    ): CommandResult;

    public function activate(AppInstance $appInstance, DeploymentRelease $release): DeploymentRelease;

    public function selected(AppInstance $appInstance): ?DeploymentRelease;

    public function retained(AppInstance $appInstance, string $name): DeploymentRelease;
}
