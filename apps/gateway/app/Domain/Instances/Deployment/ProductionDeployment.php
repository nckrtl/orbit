<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Infrastructure\Processes\CommandResult;
use App\Models\Instance;

interface ProductionDeployment
{
    public function prepare(Instance $instance, string $branch): DeploymentRelease;

    public function executeStep(
        Instance $instance,
        DeploymentRelease $release,
        DeploymentStep $step,
        DeploymentRequest $request,
    ): CommandResult;

    public function activate(Instance $instance, DeploymentRelease $release): DeploymentRelease;

    public function selected(Instance $instance): ?DeploymentRelease;

    public function retained(Instance $instance, string $name): DeploymentRelease;

    public function releases(Instance $instance): DeploymentReleaseState;

    /**
     * Removes the releases beyond DeploymentRelease::RETAINED_PER_HOME. It keeps the selected release, the previous
     * selection, and then the newest others.
     *
     * @return list<string> the names of the removed releases
     */
    public function prune(Instance $instance, DeploymentRelease $selected, ?DeploymentRelease $previous): array;
}
