<?php

declare(strict_types=1);

namespace App\Actions\AppInstances;

use App\Domain\AppInstances\Environment\AppInstanceEnvironmentOperationLock;
use App\Models\AppInstance;

final readonly class UpdateAppInstanceAction
{
    public function __construct(
        private AppInstanceDeploymentConfigResolver $resolver,
        private AppInstanceEnvironmentOperationLock $operations,
    ) {}

    public function execute(AppInstance $instance, string $branch): AppInstance
    {
        return $this->operations->run([$instance->id], function () use ($instance, $branch): AppInstance {
            $locked = AppInstance::query()->lockForUpdate()->findOrFail($instance->id);
            $this->resolver->assertAvailable($locked);
            $locked->update(['deployment_branch' => $branch]);

            return $locked->refresh();
        });
    }
}
