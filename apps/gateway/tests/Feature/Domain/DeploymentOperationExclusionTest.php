<?php

declare(strict_types=1);

use App\Actions\Instances\CreateInstanceDeployStepAction;
use App\Actions\Instances\DeployInstanceAction;
use App\Actions\Instances\DestroyInstanceDeployStepAction;
use App\Actions\Instances\ImportInstanceEnvironmentAction;
use App\Actions\Instances\RemoveInstanceAction;
use App\Actions\Instances\RollbackInstanceAction;
use App\Actions\Instances\SynchronizeInstanceEnvironmentAction;
use App\Actions\Instances\UpdateInstanceAction;
use App\Actions\Instances\UpdateInstanceDeployStepAction;
use App\Actions\Instances\UpdateInstanceEnvironmentAction;
use App\Actions\Routes\ConvergeRouteAction;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;

it('shares one Instance mutation owner across deployment and every competing operation', function (): void {
    $owner = app(InstanceEnvironmentOperationLock::class);
    $operations = [
        [DeployInstanceAction::class, 'operations'],
        [RollbackInstanceAction::class, 'operations'],
        [RemoveInstanceAction::class, 'environmentOperations'],
        [ImportInstanceEnvironmentAction::class, 'operations'],
        [UpdateInstanceEnvironmentAction::class, 'operations'],
        [SynchronizeInstanceEnvironmentAction::class, 'operations'],
        [ConvergeRouteAction::class, 'environmentOperations'],
        [CreateInstanceDeployStepAction::class, 'operations'],
        [UpdateInstanceDeployStepAction::class, 'operations'],
        [DestroyInstanceDeployStepAction::class, 'operations'],
        [UpdateInstanceAction::class, 'operations'],
    ];

    foreach ($operations as [$action, $property]) {
        $reflection = new ReflectionClass($action);

        expect($reflection->getProperty($property)->getValue(app($action)))
            ->toBe($owner);
    }
});
