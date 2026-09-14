<?php

declare(strict_types=1);

namespace App\Actions\AppInstances;

use App\Domain\AppInstances\Deployment\AppInstanceDeployStepStore;
use App\Domain\AppInstances\Deployment\DeploymentConfig;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentOperationLock;
use App\Models\AppInstance;
use Illuminate\Support\Facades\DB;

final readonly class UpdateAppInstanceDeploymentConfigAction
{
    public function __construct(
        private AppInstanceDeploymentConfigResolver $resolver,
        private AppInstanceDeployStepStore $steps,
        private AppInstanceEnvironmentOperationLock $operations,
    ) {}

    public function execute(
        AppInstance $instance,
        #[\SensitiveParameter]
        DeploymentConfig $config,
    ): DeploymentConfig {
        return $this->operations->run([$instance->id], fn (): DeploymentConfig => DB::transaction(function () use ($instance, $config): DeploymentConfig {
            $locked = AppInstance::query()->lockForUpdate()->findOrFail($instance->id);
            $this->resolver->assertAvailable($locked);
            $locked->update(['deployment_branch' => $config->branch]);
            $this->steps->replaceAll($locked, $config->steps);

            return $this->resolver->resolve($locked->refresh());
        }));
    }
}
