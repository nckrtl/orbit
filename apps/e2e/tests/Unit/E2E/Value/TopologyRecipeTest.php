<?php

declare(strict_types=1);

use App\E2E\Value\TopologyNode;
use App\E2E\Value\TopologyNodePurpose;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologyRecipe;

it('preserves the registered three-Node profile', function () {
    $recipe = TopologyRecipe::registered();

    expect($recipe->id)->toBe(TopologyProfile::NAME);
    expect($recipe->nodeKeys())->toBe(['gateway', 'app-dev', 'app-prod']);
    expect($recipe->checkoutNodeKeys())->toBe(['gateway', 'app-dev']);
    expect($recipe->assignments())->toBe(TopologyProfile::ASSIGNMENTS);
    expect(array_map(static fn (TopologyNode $node): int => $node->address, $recipe->nodes))
        ->toBe([10, 11, 12]);
});

it('maps product roles onto separate physical Node keys', function () {
    $recipe = TopologyRecipe::coldAcceptance();

    expect($recipe->nodeKeys())->toBe(['gateway', 'operator', 'app-prod', 'extra']);
    expect($recipe->nodeForRole('app-dev')->key)->toBe('operator');
    expect($recipe->node('extra')->roles)->toBe([]);
    expect($recipe->checkoutNodeKeys())->toBe(['gateway', 'operator']);
});

it('rejects an invalid Node declaration', function (TopologyNode $node) {
    expect(
        fn () => new TopologyRecipe('invalid-recipe', [
            new TopologyNode(
                'gateway',
                TopologyRecipe::BASE_IMAGE,
                TopologyNodePurpose::Gateway,
                10,
                true,
                ['gateway'],
            ),
            $node,
        ]),
    )
        ->toThrow(InvalidArgumentException::class);
})->with([
    'duplicate key' => fn (): TopologyNode => new TopologyNode(
        'gateway',
        TopologyRecipe::BASE_IMAGE,
        TopologyNodePurpose::Extension,
        11,
        false,
        [],
    ),
    'duplicate address' => fn (): TopologyNode => new TopologyNode(
        'extra',
        TopologyRecipe::BASE_IMAGE,
        TopologyNodePurpose::Extension,
        10,
        false,
        [],
    ),
]);

it('rejects unsafe Node values before recipe construction', function (
    string $key,
    string $image,
    int $address,
    array $roles,
) {
    expect(fn () => new TopologyNode(
        $key,
        $image,
        TopologyNodePurpose::Extension,
        $address,
        false,
        $roles,
    ))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'invalid key' => ['../extra', TopologyRecipe::BASE_IMAGE, 13, []],
    'unsafe image' => ['extra', 'images:ubuntu/26.04', 13, []],
    'invalid address' => ['extra', TopologyRecipe::BASE_IMAGE, 255, []],
    'duplicate role' => ['extra', TopologyRecipe::BASE_IMAGE, 13, ['app-prod', 'app-prod']],
]);

it('refuses an ambiguous singleton role lookup', function () {
    $recipe = new TopologyRecipe('ambiguous', [
        new TopologyNode('first', TopologyRecipe::BASE_IMAGE, TopologyNodePurpose::Workload, 10, false, ['app-prod']),
        new TopologyNode('second', TopologyRecipe::BASE_IMAGE, TopologyNodePurpose::Workload, 11, false, ['app-prod']),
    ]);

    expect(fn () => $recipe->nodeForRole('app-prod'))
        ->toThrow(InvalidArgumentException::class, 'exactly one physical Node');
});
