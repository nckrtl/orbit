<?php

declare(strict_types=1);

use App\Actions\AppInstances\AdoptProductionLayoutAction;
use App\Actions\AppInstances\DeployAppInstanceAction;
use App\Actions\AppInstances\ImportAppInstanceEnvironmentAction;
use App\Actions\AppInstances\RemoveAppInstanceAction;
use App\Actions\AppInstances\RollbackAppInstanceAction;
use App\Actions\AppInstances\SynchronizeAppInstanceEnvironmentAction;
use App\Actions\AppInstances\UpdateAppInstanceDeploymentConfigAction;
use App\Actions\AppInstances\UpdateAppInstanceEnvironmentAction;
use App\Actions\Routes\ConvergeRouteAction;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentOperationLock;
use ReflectionClass;

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
    ];

    foreach ($operations as [$action, $property]) {
        $reflection = new ReflectionClass($action);

        expect($reflection->getProperty($property)->getValue(app($action)))
            ->toBe($owner);
    }
});

it('keeps deployment configuration replacement outside the mutation owner', function (): void {
    $reflection = new ReflectionClass(UpdateAppInstanceDeploymentConfigAction::class);

    expect(array_map(
        static fn (ReflectionProperty $property): string => $property->getName(),
        $reflection->getProperties(),
    ))
        ->not->toContain('operations', 'environmentOperations');
});
