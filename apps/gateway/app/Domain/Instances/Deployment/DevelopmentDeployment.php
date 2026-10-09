<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Domain\Projects\DevelopmentDeployStep;
use App\Infrastructure\Processes\CommandResult;
use App\Models\Instance;

/** Deploys a development default in its own checkout. */
interface DevelopmentDeployment
{
    /** Turns an old release layout into a plain checkout once and returns the commit it serves. */
    public function convert(Instance $instance): string;

    /** Fetches the Project's default branch. */
    public function target(Instance $instance): DevelopmentTarget;

    public function checkout(Instance $instance, string $commit, DeploymentRequest $request): void;

    public function executeStep(Instance $instance, DevelopmentDeployStep $step, DeploymentRequest $request): CommandResult;

    /**
     * Removes what is left of the old release layout after `convert`.
     *
     * @param  list<string>  $consumers  The checkouts seeded from this default.
     */
    public function removeReleases(Instance $instance, array $consumers): void;
}
