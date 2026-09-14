<?php

declare(strict_types=1);

use App\Actions\AppInstances\AdoptProductionLayoutAction;
use App\Actions\AppInstances\CreateAppInstanceDeployStepAction;
use App\Actions\AppInstances\DeployAppInstanceAction;
use App\Actions\AppInstances\DestroyAppInstanceDeployStepAction;
use App\Actions\AppInstances\ImportAppInstanceEnvironmentAction;
use App\Actions\AppInstances\RemoveAppInstanceAction;
use App\Actions\AppInstances\RollbackAppInstanceAction;
use App\Actions\AppInstances\SynchronizeAppInstanceEnvironmentAction;
use App\Actions\AppInstances\UpdateAppInstanceAction;
use App\Actions\AppInstances\UpdateAppInstanceDeploymentConfigAction;
use App\Actions\AppInstances\UpdateAppInstanceDeployStepAction;
use App\Actions\AppInstances\UpdateAppInstanceEnvironmentAction;
use App\Actions\Routes\ConvergeRouteAction;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentOperationLock;

it('shares one AppInstance mutation owner across deployment and every competing operation', function (): void {
    $owner = app(AppInstanceEnvironmentOperationLock::class);
    $operations = [
        [DeployAppInstanceAction::class, 'operations'],
        [RollbackAppInstanceAction::class, 'operations'],
        [AdoptProductionLayoutAction::class, 'operations'],
        [RemoveAppInstanceAction::class, 'environmentOperations'],
        [ImportAppInstanceEnvironmentAction::class, 'operations'],
        [UpdateAppInstanceEnvironmentAction::class, 'operations'],
        [SynchronizeAppInstanceEnvironmentAction::class, 'operations'],
        [ConvergeRouteAction::class, 'environmentOperations'],
        [UpdateAppInstanceDeploymentConfigAction::class, 'operations'],
        [CreateAppInstanceDeployStepAction::class, 'operations'],
        [UpdateAppInstanceDeployStepAction::class, 'operations'],
        [DestroyAppInstanceDeployStepAction::class, 'operations'],
        [UpdateAppInstanceAction::class, 'operations'],
    ];

    foreach ($operations as [$action, $property]) {
        $reflection = new ReflectionClass($action);

        expect($reflection->getProperty($property)->getValue(app($action)))
            ->toBe($owner);
    }
});
