<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Domain\Instances\Deployment\DeploymentConfig;
use App\Domain\Instances\Deployment\InstanceDeployStepStore;
use App\Domain\Instances\InstanceState;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitBranchName;
use App\Models\Instance;
use App\Models\InstanceAppProjection;
use InvalidArgumentException;

final readonly class InstanceDeploymentConfigResolver
{
    public function __construct(private InstanceDeployStepStore $steps) {}

    public function resolve(Instance $instance): DeploymentConfig
    {
        $this->assertAvailable($instance);
        $branch = is_string($instance->deployment_branch) ? $instance->deployment_branch : $instance->branch;
        assert(is_string($branch));

        try {
            return new DeploymentConfig($branch, $this->steps->ordered($instance));
        } catch (InvalidArgumentException|\ValueError) {
            throw $this->unavailable();
        }
    }

    public function assertAvailable(Instance $instance): void
    {
        InstanceAppProjection::assertAvailable([$instance->id]);
        if (
            ! $instance->placedOnAppProd()
            || ! in_array($instance->status, [InstanceState::SourceResolved, InstanceState::Active], strict: true)
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
            message: 'Deployment configuration is unavailable for this Instance.',
            status: 409,
        );
    }
}
