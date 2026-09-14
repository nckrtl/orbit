<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeRoleDependencyInspector;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Nodes\EloquentNodeRoleDependencyInspector;
use App\Models\Node;

describe(EloquentNodeRoleDependencyInspector::class, function (): void {
    it('returns empty leftover Instance and Workspace id lists for app-dev', function (): void {
        $dependencies = app(NodeRoleDependencyInspector::class)->inspect(
            dependency_node('dependency-dev'),
            RoleName::AppDev,
        );

        expect($dependencies->instanceIds)
            ->toBeEmpty()
            ->and($dependencies->workspaceIds)
            ->toBeEmpty()
            ->and($dependencies->processIds)
            ->toBeEmpty()
            ->and($dependencies->summaries)
            ->toBeEmpty();
    });

    it('returns empty leftover Instance and Workspace id lists for app-prod', function (): void {
        $dependencies = app(NodeRoleDependencyInspector::class)->inspect(
            dependency_node('dependency-prod'),
            RoleName::AppProd,
        );

        expect($dependencies->instanceIds)
            ->toBeEmpty()
            ->and($dependencies->workspaceIds)
            ->toBeEmpty()
            ->and($dependencies->processIds)
            ->toBeEmpty()
            ->and($dependencies->summaries)
            ->toBeEmpty();
    });

    it('returns an empty deterministic set for roles without application dependents', function (RoleName $role): void {
        $dependencies = app(NodeRoleDependencyInspector::class)->inspect(
            dependency_node('dependency-empty-'.$role->value),
            $role,
        );

        expect($dependencies->instanceIds)
            ->toBeEmpty()
            ->and($dependencies->workspaceIds)
            ->toBeEmpty()
            ->and($dependencies->processIds)
            ->toBeEmpty()
            ->and($dependencies->summaries)
            ->toBeEmpty();
    })->with([
        'gateway' => RoleName::Gateway,
        'database' => RoleName::Database,
    ]);
});

function dependency_node(string $name): Node
{
    return Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.90',
    ]);
}
