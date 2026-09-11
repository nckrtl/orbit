<?php

declare(strict_types=1);

namespace App\Actions\AppInstances;

use App\Domain\AppInstances\AppInstanceState;
use App\Domain\AppInstances\Deployment\DeploymentConfig;
use App\Domain\AppInstances\Deployment\DeploymentPhase;
use App\Domain\AppInstances\Deployment\DeploymentStep;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitBranchName;
use App\Models\AppInstance;
use InvalidArgumentException;

final readonly class AppInstanceDeploymentConfigResolver
{
    public function resolve(AppInstance $instance): DeploymentConfig
    {
        $this->assertAvailable($instance);
        $branch = is_string($instance->deployment_branch) ? $instance->deployment_branch : $instance->branch;
        assert(is_string($branch));

        try {
            $steps = array_map(
                static function (array $step): DeploymentStep {
                    $name = $step['name'] ?? null;
                    $phase = $step['phase'] ?? null;
                    $command = $step['command'] ?? null;
                    $timeout = $step['timeout_seconds'] ?? null;

                    if (! is_string($name) || ! is_string($phase) || ! is_string($command) || ! is_int($timeout)) {
                        throw new InvalidArgumentException('Stored deployment step shape is invalid.');
                    }

                    return new DeploymentStep(
                        $name,
                        DeploymentPhase::from($phase),
                        $command,
                        $timeout,
                    );
                },
                $instance->deployment_steps,
            );

            return new DeploymentConfig($branch, $steps);
        } catch (InvalidArgumentException|\ValueError) {
            throw $this->unavailable();
        }
    }

    public function assertAvailable(AppInstance $instance): void
    {
        if (
            $instance->environment !== 'production'
            || ! in_array($instance->status, [AppInstanceState::SourceResolved, AppInstanceState::Active], strict: true)
            || ! is_string($instance->branch)
            || ! GitBranchName::isValid($instance->branch)
            || (is_string($instance->deployment_branch) && ! GitBranchName::isValid($instance->deployment_branch))
        ) {
            throw $this->unavailable();
        }
    }

    private function unavailable(): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'deployment_config.unavailable',
            message: 'Deployment configuration is unavailable for this AppInstance.',
            status: 409,
        );
    }
}
