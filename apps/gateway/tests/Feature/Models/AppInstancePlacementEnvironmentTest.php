<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\NodeRole;
use Illuminate\Database\Eloquent\Collection;

/** @param list<array{RoleName, LifecycleStatus}> $roles */
function placement_instance(array $roles): AppInstance
{
    $node = new Node;
    $node->setRelation('roles', new Collection(array_map(
        static fn (array $role): NodeRole => new NodeRole(['role' => $role[0], 'status' => $role[1]]),
        $roles,
    )));
    $instance = new AppInstance;
    $instance->setRelation('node', $node);

    return $instance;
}

it('places an Instance by its Node app role in every lifecycle state', function (RoleName $role, LifecycleStatus $status, string $environment): void {
    expect(placement_instance([[$role, $status], [RoleName::Metrics, LifecycleStatus::Active]])->placementEnvironment())->toBe($environment);
})->with([
    'active app-dev' => [RoleName::AppDev, LifecycleStatus::Active, 'development'],
    'converging app-dev' => [RoleName::AppDev, LifecycleStatus::Provisioning, 'development'],
    'failed app-dev' => [RoleName::AppDev, LifecycleStatus::Failed, 'development'],
    'removing app-prod' => [RoleName::AppProd, LifecycleStatus::Removing, 'production'],
]);

it('has no placement without exactly one app role', function (): void {
    expect(placement_instance([[RoleName::Metrics, LifecycleStatus::Active]])->placementEnvironment())->toBeNull();
});
