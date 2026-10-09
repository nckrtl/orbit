<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Domain\Projects\DevelopmentDeployStep;
use App\Infrastructure\Processes\CommandResult;
use App\Models\Instance;

interface DevelopmentDeployment
{
    public function initialize(Instance $instance): void;

    public function target(Instance $instance): string;

    public function selected(Instance $instance): DeploymentRelease;

    public function releases(Instance $instance): DeploymentReleaseState;

    public function prepare(Instance $instance, string $commit): DeploymentRelease;

    public function executeStep(Instance $instance, DeploymentRelease $release, DevelopmentDeployStep $step, DeploymentRequest $request): CommandResult;

    public function activate(Instance $instance, DeploymentRelease $release): DeploymentRelease;

    /**
     * Keeps the selected release and every seed another Instance leases, then the previous selection while the home
     * holds fewer than DeploymentRelease::RETAINED_PER_HOME. It removes the other releases.
     */
    public function prune(Instance $instance, DeploymentRelease $selected): void;
}
