<?php

declare(strict_types=1);

namespace App\Actions\AppInstances;

use App\Domain\AppInstances\Deployment\DeploymentConfig;
use App\Models\AppInstance;
use Illuminate\Support\Facades\DB;

final readonly class UpdateAppInstanceDeploymentConfigAction
{
    public function __construct(private AppInstanceDeploymentConfigResolver $resolver) {}

    public function execute(
        AppInstance $instance,
        #[\SensitiveParameter]
        DeploymentConfig $config,
    ): DeploymentConfig {
        return DB::transaction(function () use ($instance, $config): DeploymentConfig {
            $locked = AppInstance::query()->lockForUpdate()->findOrFail($instance->id);
            $this->resolver->assertAvailable($locked);
            $locked->update([
                'deployment_branch' => $config->branch,
                'deployment_steps' => $config->normalizedSteps(),
            ]);

            return $this->resolver->resolve($locked->refresh());
        });
    }
}
