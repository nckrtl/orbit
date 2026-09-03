<?php

declare(strict_types=1);

use App\E2E\Value\AttemptId;
use App\E2E\Value\ColdTopologyPlan;
use App\E2E\Value\LaravelRelease;
use App\E2E\Value\OperationId;
use App\E2E\Value\TopologyNode;
use App\E2E\Value\TopologyNodePurpose;
use App\E2E\Value\TopologyPersistence;
use App\E2E\Value\TopologyRecipe;
use App\E2E\Value\TopologyTarget;

function cold_topology_plan(TopologyTarget $target, TopologyRecipe $recipe): ColdTopologyPlan
{
    $operation = new OperationId(str_repeat('d', 32));

    return new ColdTopologyPlan(
        $target,
        $recipe,
        '/tmp/orbit',
        str_repeat('a', 40),
        [TopologyRecipe::BASE_IMAGE => str_repeat('b', 64)],
        new LaravelRelease('v13.0.0', str_repeat('c', 40)),
        $operation,
        ['user.orbit.e2e.operation' => $operation->value],
        TopologyPersistence::Disposable,
    );
}

it('rejects a recipe that differs from the target beyond its Node keys', function () {
    $targetRecipe = TopologyRecipe::coldAcceptance();
    $planRecipe = new TopologyRecipe('cold-acceptance', [
        ...array_slice($targetRecipe->nodes, 0, 3),
        new TopologyNode(
            'extra',
            TopologyRecipe::BASE_IMAGE,
            TopologyNodePurpose::Extension,
            14,
            false,
            [],
        ),
    ]);
    $target = TopologyTarget::disposableCold(
        'ORB-106',
        new AttemptId(str_repeat('a', 32)),
        $targetRecipe,
    );

    expect(fn () => cold_topology_plan($target, $planRecipe))
        ->toThrow(InvalidArgumentException::class, 'target and recipe inventories differ');
});

it('rejects ambiguous required role mappings before construction', function () {
    $recipe = new TopologyRecipe('ambiguous-cold', [
        new TopologyNode('gateway', TopologyRecipe::BASE_IMAGE, TopologyNodePurpose::Gateway, 10, true, ['gateway']),
        new TopologyNode(
            'operator-a',
            TopologyRecipe::BASE_IMAGE,
            TopologyNodePurpose::Operator,
            11,
            true,
            ['app-dev'],
        ),
        new TopologyNode(
            'operator-b',
            TopologyRecipe::BASE_IMAGE,
            TopologyNodePurpose::Operator,
            12,
            false,
            ['app-dev'],
        ),
        new TopologyNode(
            'app-prod',
            TopologyRecipe::BASE_IMAGE,
            TopologyNodePurpose::Workload,
            13,
            false,
            ['app-prod'],
        ),
    ]);
    $target = TopologyTarget::disposableCold(
        'ORB-106',
        new AttemptId(str_repeat('a', 32)),
        $recipe,
    );

    expect(fn () => cold_topology_plan($target, $recipe))
        ->toThrow(InvalidArgumentException::class, 'exactly one physical Node');
});
