<?php

declare(strict_types=1);

namespace App\Actions\AppInstances;

use App\Data\AppInstances\DeploymentStepData;
use App\Domain\AppInstances\Deployment\AppInstanceDeployStepStore;
use App\Domain\AppInstances\Deployment\DeploymentPhase;
use App\Domain\AppInstances\Deployment\DeploymentStep;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentOperationLock;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Models\AppInstance;

final readonly class UpdateAppInstanceDeployStepAction
{
    public function __construct(
        private AppInstanceDeploymentConfigResolver $resolver,
        private AppInstanceDeployStepStore $steps,
        private AppInstanceEnvironmentOperationLock $operations,
        private ?RecordEventBroadcaster $broadcaster = null,
    ) {}

    public function execute(
        AppInstance $instance,
        string $name,
        ?string $command,
        ?DeploymentPhase $phase,
        ?int $timeoutSeconds,
        ?string $before,
        ?string $after,
        bool $hasCommand,
        bool $hasPhase,
        bool $hasTimeout,
    ): DeploymentStep {
        $result = $this->operations->run([$instance->id], function () use (
            $instance,
            $name,
            $command,
            $phase,
            $timeoutSeconds,
            $before,
            $after,
            $hasCommand,
            $hasPhase,
            $hasTimeout,
        ): DeploymentStep {
            $locked = AppInstance::query()->lockForUpdate()->findOrFail($instance->id);
            $this->resolver->assertAvailable($locked);

            return $this->steps->update(
                $locked,
                $name,
                $command,
                $phase,
                $timeoutSeconds,
                $before,
                $after,
                $hasCommand,
                $hasPhase,
                $hasTimeout,
            );
        });

        ($this->broadcaster ?? app(RecordEventBroadcaster::class))->broadcast(
            RecordEventType::DeployStepUpdated,
            $result->name,
            DeploymentStepData::fromDomain($result)->toArray(),
        );

        return $result;
    }
}
