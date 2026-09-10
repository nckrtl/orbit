<?php

declare(strict_types=1);

use App\E2E\Git\GitRepository;
use App\E2E\ScenarioCatalog;
use App\E2E\Value\ScenarioAction;
use App\E2E\Value\ScenarioDefinition;
use App\E2E\Value\ScenarioId;
use App\E2E\Value\TopologyEndState;
use App\E2E\Value\TopologyRecipe;
use Illuminate\Container\Container;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Facades\Facade;

beforeEach(function (): void {
    $container = new Container;
    $container->instance(ProcessFactory::class, new ProcessFactory);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($container);
});

function catalogScenarioDefinition(string $id, string $lane): ScenarioDefinition
{
    $recipe = TopologyRecipe::registered();
    $actions = $lane === 'snapshot'
        ? [
            new ScenarioAction('setup', 'prepare', 60),
            new ScenarioAction('exercise', 'lifecycle', 60),
            new ScenarioAction('assertion', 'verify', 60),
        ]
        : [new ScenarioAction('setup', 'prepare', 60)];

    return new ScenarioDefinition(
        new ScenarioId($id),
        $lane,
        $recipe,
        $actions,
        ['apps/e2e/resources/guest/prepare-node.sh' => str_repeat('a', 64)],
        TopologyEndState::complete($recipe),
        false,
        "{$id} filter",
    );
}

it('selects only scenarios from the requested lane in catalog order', function (): void {
    $repository = new GitRepository(dirname(__DIR__, 5));
    $candidate = $repository->commit();
    $definitions = [
        catalogScenarioDefinition('cold-flow', 'cold'),
        catalogScenarioDefinition('snapshot-first', 'snapshot'),
        catalogScenarioDefinition('snapshot-second', 'snapshot'),
    ];
    $catalog = new ScenarioCatalog($repository, fn (): array => $definitions);

    $selected = $catalog->select($candidate, [], 'snapshot');

    expect(array_map(
        static fn (ScenarioDefinition $definition): string => $definition->id->value,
        $selected,
    ))->toBe(['snapshot-first', 'snapshot-second']);
});

it('preserves explicit selection order within one lane', function (): void {
    $repository = new GitRepository(dirname(__DIR__, 5));
    $candidate = $repository->commit();
    $definitions = [
        catalogScenarioDefinition('snapshot-first', 'snapshot'),
        catalogScenarioDefinition('snapshot-second', 'snapshot'),
    ];
    $catalog = new ScenarioCatalog($repository, fn (): array => $definitions);

    $selected = $catalog->select($candidate, ['snapshot-second', 'snapshot-first'], 'snapshot');

    expect(array_map(
        static fn (ScenarioDefinition $definition): string => $definition->id->value,
        $selected,
    ))->toBe(['snapshot-second', 'snapshot-first']);
});

it('rejects invalid lanes and scenarios selected from another lane', function (): void {
    $repository = new GitRepository(dirname(__DIR__, 5));
    $candidate = $repository->commit();
    $catalog = new ScenarioCatalog($repository, fn (): array => [
        catalogScenarioDefinition('cold-flow', 'cold'),
        catalogScenarioDefinition('snapshot-flow', 'snapshot'),
    ]);

    expect(fn () => $catalog->select($candidate, [], 'live'))
        ->toThrow(InvalidArgumentException::class, 'lane [live] is invalid');
    expect(fn () => $catalog->select($candidate, ['cold-flow'], 'snapshot'))
        ->toThrow(InvalidArgumentException::class, 'does not belong to the [snapshot] lane');
});

it('declares the committed snapshot scenarios with their lane recipes and actions', function (): void {
    $repository = new GitRepository(dirname(__DIR__, 5));
    $candidate = $repository->commit();
    $catalog = new ScenarioCatalog($repository);

    $definitions = $catalog->select($candidate, [], 'snapshot');

    expect(array_map(
        static fn (ScenarioDefinition $definition): string => $definition->id->value,
        $definitions,
    ))->toBe(['snapshot-lifecycle', 'snapshot-isolation', 'snapshot-extension']);
    expect(array_map(
        static fn (ScenarioDefinition $definition): array => $definition->recipe->nodeKeys(),
        $definitions,
    ))->toBe([
        ['gateway', 'app-dev', 'app-prod'],
        ['gateway', 'app-dev', 'app-prod'],
        ['gateway', 'app-dev', 'app-prod', 'app-prod-2'],
    ]);
    expect(array_map(
        static fn (ScenarioDefinition $definition): array => array_column($definition->normalized()['actions'], 'name'),
        $definitions,
    ))->toBe([
        ['prepare', 'lifecycle', 'verify'],
        ['prepare', 'isolation', 'verify'],
        ['prepare', 'extension', 'verify'],
    ]);
});
