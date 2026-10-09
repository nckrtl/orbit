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
     * selection, and then the newest others, and also every release a running process of the Instance still uses.
     * `in_use` names the releases kept only because a running process uses them.
     *
     * @return array{removed: list<string>, in_use: list<string>}
     */
    public function prune(Instance $instance, DeploymentRelease $selected, ?DeploymentRelease $previous): array;
}
