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

function scenarioDefinition(array $inputs = ['resources/host/prepare-node.sh' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa']): ScenarioDefinition
{
    $recipe = TopologyRecipe::coldAcceptance();

    return new ScenarioDefinition(
        new ScenarioId('cold-four-node'),
        'cold',
        $recipe,
        [
            new ScenarioAction('setup', 'construct', 3600),
            new ScenarioAction('assertion', 'verify', 900),
        ],
        $inputs,
        TopologyEndState::complete($recipe),
        false,
        'cold-scenario-suite constructs and releases the four-Node topology',
    );
}

it('normalizes a complete cold definition and fingerprints every declared input', function (): void {
    $definition = scenarioDefinition();

    expect($definition->normalized())
        ->toMatchArray([
            'id' => 'cold-four-node',
            'lane' => 'cold',
            'observes_php' => false,
        ]);
    expect($definition->fingerprint())->toMatch('/\A[a-f0-9]{64}\z/');
    expect(scenarioDefinition([
        'resources/host/prepare-node.sh' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
    ])->fingerprint())->not->toBe($definition->fingerprint());
});

it('changes its fingerprint when a bounded action changes', function (): void {
    $definition = scenarioDefinition();
    $recipe = TopologyRecipe::coldAcceptance();
    $changed = new ScenarioDefinition(
        $definition->id,
        'cold',
        $recipe,
        [new ScenarioAction('setup', 'construct', 3599)],
        $definition->declaredInputs,
        TopologyEndState::complete($recipe),
        false,
        $definition->pestFilter,
    );

    expect($changed->fingerprint())->not->toBe($definition->fingerprint());
});

it('rejects invalid IDs, absent deadlines, and invalid declared inputs before execution', function (Closure $action): void {
    expect($action)->toThrow(InvalidArgumentException::class);
})->with([
    'invalid ID' => [fn () => new ScenarioId('../cold')],
    'missing deadline' => [fn () => new ScenarioAction('setup', 'construct', 0)],
    'unsafe input path' => [fn () => scenarioDefinition(['../secret' => str_repeat('a', 64)])],
    'invalid input digest' => [fn () => scenarioDefinition(['resources/host/prepare-node.sh' => 'main'])],
]);

it('rejects unknown, repeated, and duplicate catalog IDs before execution', function (): void {
    $container = new Container;
    $container->instance(ProcessFactory::class, new ProcessFactory);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($container);
    $repository = new GitRepository(dirname(__DIR__, 5));
    $candidate = $repository->commit();
    $catalog = new ScenarioCatalog($repository);

    expect($catalog->definitions($candidate)[0]->expectedEndState->toArray())
        ->toBe(['nodes' => ['gateway', 'operator', 'app-prod']]);

    expect(fn () => $catalog->select($candidate, ['unknown-scenario']))
        ->toThrow(InvalidArgumentException::class, 'is unknown');
    expect(fn () => $catalog->select($candidate, ['cold-four-node', 'cold-four-node']))
        ->toThrow(InvalidArgumentException::class, 'selected more than once');

    $definition = scenarioDefinition();
    $duplicates = new ScenarioCatalog($repository, fn (): array => [$definition, $definition]);
    expect(fn () => $duplicates->definitions($candidate))
        ->toThrow(InvalidArgumentException::class, 'is duplicated');
});
