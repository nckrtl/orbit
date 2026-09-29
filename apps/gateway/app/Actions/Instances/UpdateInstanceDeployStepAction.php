<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Data\Instances\DeploymentStepData;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Instances\Deployment\DeploymentPhase;
use App\Domain\Instances\Deployment\DeploymentStep;
use App\Domain\Instances\Deployment\InstanceDeployStepStore;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Models\Instance;

final readonly class UpdateInstanceDeployStepAction
{
    public function __construct(
        private InstanceDeploymentConfigResolver $resolver,
        private InstanceDeployStepStore $steps,
        private InstanceEnvironmentOperationLock $operations,
        private ?RecordEventBroadcaster $broadcaster = null,
    ) {}

    public function execute(
        Instance $instance,
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
            $locked = Instance::query()->lockForUpdate()->findOrFail($instance->id);
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
