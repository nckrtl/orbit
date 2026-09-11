<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Git\GitRepository;
use App\E2E\Value\ScenarioAction;
use App\E2E\Value\ScenarioDefinition;
use App\E2E\Value\ScenarioId;
use App\E2E\Value\TopologyEndState;
use App\E2E\Value\TopologyExtension;
use App\E2E\Value\TopologyRecipe;
use Closure;
use InvalidArgumentException;

final readonly class ScenarioCatalog
{
    public function __construct(
        private GitRepository $repository,
        private ?Closure $factory = null,
    ) {}

    /** @return list<ScenarioDefinition> */
    public function definitions(string $candidate): array
    {
        $definitions = $this->factory === null
            ? $this->committedDefinitions($candidate)
            : ($this->factory)($candidate);
        if (! is_array($definitions) || $definitions === [] || ! array_is_list($definitions)) {
            throw new InvalidArgumentException('The scenario catalog is invalid.');
        }

        $ids = [];
        foreach ($definitions as $definition) {
            if (! $definition instanceof ScenarioDefinition) {
                throw new InvalidArgumentException('Every scenario catalog entry must be a definition.');
            }
            if (isset($ids[$definition->id->value])) {
                throw new InvalidArgumentException("Scenario ID [{$definition->id->value}] is duplicated.");
            }
            $ids[$definition->id->value] = true;
        }

        return $definitions;
    }

    /**
     * @param  list<string>  $selected
     * @return list<ScenarioDefinition>
     */
    public function select(string $candidate, array $selected, ?string $lane = 'cold'): array
    {
        if ($lane !== null && ! in_array($lane, ['cold', 'snapshot'], true)) {
            throw new InvalidArgumentException("Scenario lane [{$lane}] is invalid.");
        }
        $definitions = $this->definitions($candidate);
        if ($selected === []) {
            if ($lane === null) {
                return $definitions;
            }

            return array_values(array_filter(
                $definitions,
                static fn (ScenarioDefinition $definition): bool => $definition->lane === $lane,
            ));
        }

        $requested = [];
        foreach ($selected as $id) {
            $scenario = new ScenarioId($id);
            if (isset($requested[$scenario->value])) {
                throw new InvalidArgumentException("Scenario ID [{$scenario->value}] was selected more than once.");
            }
            $requested[$scenario->value] = true;
        }

        $byId = [];
        foreach ($definitions as $definition) {
            $byId[$definition->id->value] = $definition;
        }

        $resolved = [];
        foreach (array_keys($requested) as $id) {
            $definition = $byId[$id] ?? throw new InvalidArgumentException("Scenario ID [{$id}] is unknown.");
            if ($lane !== null && $definition->lane !== $lane) {
                throw new InvalidArgumentException("Scenario ID [{$id}] does not belong to the [{$lane}] lane.");
            }
            $resolved[] = $definition;
        }

        return $resolved;
    }

    /** @return list<ScenarioDefinition> */
    private function committedDefinitions(string $candidate): array
    {
        $coldPatterns = [
            'apps/e2e/app/E2E/ColdTopologyConstructor.php',
            'apps/e2e/app/E2E/TopologyConverger.php',
            'apps/e2e/app/E2E/TopologyVerifier.php',
            'apps/e2e/resources/host/*.py',
            'apps/e2e/resources/guest/*.sh',
        ];
        $coldInputs = [];
        foreach ($this->repository->blobs($candidate, $coldPatterns) as $path => $contents) {
            $coldInputs[$path] = hash('sha256', $contents);
        }
        ksort($coldInputs, SORT_STRING);
        $coldRecipe = TopologyRecipe::coldAcceptance();
        $coldExpected = TopologyEndState::fromArray([
            'nodes' => ['gateway', 'operator', 'app-prod'],
        ], $coldRecipe);

        $snapshotPatterns = [
            'apps/e2e/resources/host/*.py',
            'apps/e2e/resources/guest/*.sh',
        ];
        $snapshotInputs = [];
        foreach ($this->repository->blobs($candidate, $snapshotPatterns) as $path => $contents) {
            $snapshotInputs[$path] = hash('sha256', $contents);
        }
        ksort($snapshotInputs, SORT_STRING);
        $snapshotRecipe = TopologyRecipe::registered();
        $extendedSnapshotRecipe = TopologyRecipe::extendedAppProd();

        return [
            new ScenarioDefinition(
                new ScenarioId('cold-four-node'),
                'cold',
                $coldRecipe,
                [
                    new ScenarioAction('setup', 'construct', 3600),
                    new ScenarioAction('assertion', 'verify', 900),
                ],
                $coldInputs,
                $coldExpected,
                false,
                'cold-scenario-suite constructs and releases the four-Node topology',
            ),
            new ScenarioDefinition(
                new ScenarioId('cold-construction-cleanup'),
                'cold',
                $coldRecipe,
                [new ScenarioAction('exercise', 'injected-source-failure', 900)],
                $coldInputs,
                $coldExpected,
                false,
                'cold-scenario-suite-cleanup releases exact resources after construction failure',
                true,
            ),
            new ScenarioDefinition(
                new ScenarioId('snapshot-lifecycle'),
                'snapshot',
                $snapshotRecipe,
                [
                    new ScenarioAction('setup', 'prepare', 3600),
                    new ScenarioAction('exercise', 'lifecycle', 900),
                    new ScenarioAction('assertion', 'verify', 900),
                ],
                $snapshotInputs,
                TopologyEndState::complete($snapshotRecipe),
                false,
                'snapshot-scenario-lifecycle',
            ),
            new ScenarioDefinition(
                new ScenarioId('snapshot-isolation'),
                'snapshot',
                $snapshotRecipe,
                [
                    new ScenarioAction('setup', 'prepare', 3600),
                    new ScenarioAction('exercise', 'isolation', 900),
                    new ScenarioAction('assertion', 'verify', 900),
                ],
                $snapshotInputs,
                TopologyEndState::complete($snapshotRecipe),
                false,
                'snapshot-scenario-isolation',
            ),
            new ScenarioDefinition(
                new ScenarioId('snapshot-extension'),
                'snapshot',
                $extendedSnapshotRecipe,
                [
                    new ScenarioAction('setup', 'prepare', 3600),
                    new ScenarioAction('exercise', 'extension', 900),
                    new ScenarioAction('assertion', 'verify', 900),
                ],
                $snapshotInputs,
                TopologyEndState::complete($extendedSnapshotRecipe),
                false,
                'snapshot-scenario-extension',
                extension: TopologyExtension::AppProd,
            ),
        ];
    }
}
