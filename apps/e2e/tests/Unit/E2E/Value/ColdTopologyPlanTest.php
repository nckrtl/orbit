<?php

declare(strict_types=1);

use App\E2E\Value\AttemptId;
use App\E2E\Value\ColdTopologyPlan;
use App\E2E\Value\LaravelRelease;
use App\E2E\Value\OperationId;
use App\E2E\Value\TopologyNode;
use App\E2E\Value\TopologyNodePurpose;
use App\E2E\Value\TopologyRecipe;
use App\E2E\Value\TopologyTarget;

function cold_topology_plan(TopologyTarget $target): ColdTopologyPlan
{
    $operation = new OperationId(str_repeat('d', 32));

    return new ColdTopologyPlan(
        $target,
        '/tmp/orbit',
        str_repeat('a', 40),
        [TopologyRecipe::BASE_IMAGE => str_repeat('b', 64)],
        new LaravelRelease('v13.0.0', str_repeat('c', 40)),
        $operation,
        ['user.orbit.e2e.operation' => $operation->value],
    );
}

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

    expect(fn () => cold_topology_plan($target))
        ->toThrow(InvalidArgumentException::class, 'exactly one physical Node');
});

it('rejects required roles assigned to the same physical Node before construction', function () {
    $recipe = new TopologyRecipe('combined-cold', [
        new TopologyNode('gateway', TopologyRecipe::BASE_IMAGE, TopologyNodePurpose::Gateway, 10, true, ['gateway']),
        new TopologyNode(
            'combined',
            TopologyRecipe::BASE_IMAGE,
            TopologyNodePurpose::Operator,
            11,
            true,
            ['app-dev', 'app-prod'],
        ),
    ]);
    $target = TopologyTarget::disposableCold('ORB-106', new AttemptId(str_repeat('a', 32)), $recipe);

    expect(fn () => cold_topology_plan($target))
        ->toThrow(InvalidArgumentException::class, 'distinct physical Node');
});

it('rejects invalid additional Incus metadata before construction', function (array $metadata) {
    $target = TopologyTarget::disposableCold(
        'ORB-106',
        new AttemptId(str_repeat('a', 32)),
        TopologyRecipe::coldAcceptance(),
    );
    $operation = new OperationId(str_repeat('d', 32));

    expect(fn () => new ColdTopologyPlan(
        $target,
        '/tmp/orbit',
        str_repeat('a', 40),
        [TopologyRecipe::BASE_IMAGE => str_repeat('b', 64)],
        new LaravelRelease('v13.0.0', str_repeat('c', 40)),
        $operation,
        [...$metadata, 'user.orbit.e2e.operation' => $operation->value],
    ))
        ->toThrow(InvalidArgumentException::class, 'ownership metadata is invalid');
})->with([
    'foreign key' => [['foo' => 'bar']],
    'owner override' => [['user.orbit.e2e.owner' => 'someone-else']],
    'nul value' => [['user.orbit.e2e.issue' => "ORB-106\0"]],
]);

it('derives disposable persistence from the absence of a fixed slot', function () {
    $target = TopologyTarget::disposableCold(
        'ORB-106',
        new AttemptId(str_repeat('a', 32)),
        TopologyRecipe::coldAcceptance(),
    );

    expect(cold_topology_plan($target)->isDisposable())->toBeTrue();
});
