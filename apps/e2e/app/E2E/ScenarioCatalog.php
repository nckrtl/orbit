<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Git\GitRepository;
use App\E2E\Value\ScenarioAction;
use App\E2E\Value\ScenarioDefinition;
use App\E2E\Value\ScenarioId;
use App\E2E\Value\TopologyEndState;
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
    public function select(string $candidate, array $selected): array
    {
        $definitions = $this->definitions($candidate);
        if ($selected === []) {
            return $definitions;
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
            $resolved[] = $byId[$id] ?? throw new InvalidArgumentException("Scenario ID [{$id}] is unknown.");
        }

        return $resolved;
    }

    /** @return list<ScenarioDefinition> */
    private function committedDefinitions(string $candidate): array
    {
        $patterns = [
            'apps/e2e/app/E2E/ColdTopologyConstructor.php',
            'apps/e2e/app/E2E/TopologyConverger.php',
            'apps/e2e/app/E2E/TopologyVerifier.php',
            'apps/e2e/resources/host/*.py',
            'apps/e2e/resources/guest/*.sh',
        ];
        $inputs = [];
        foreach ($this->repository->blobs($candidate, $patterns) as $path => $contents) {
            $inputs[$path] = hash('sha256', $contents);
        }
        ksort($inputs, SORT_STRING);
        $recipe = TopologyRecipe::coldAcceptance();
        $expected = TopologyEndState::complete($recipe);

        return [
            new ScenarioDefinition(
                new ScenarioId('cold-four-node'),
                'cold',
                $recipe,
                [
                    new ScenarioAction('setup', 'construct', 3600),
                    new ScenarioAction('assertion', 'verify', 900),
                ],
                $inputs,
                $expected,
                false,
                'cold-scenario-suite constructs and releases the four-Node topology',
            ),
            new ScenarioDefinition(
                new ScenarioId('cold-construction-cleanup'),
                'cold',
                $recipe,
                [new ScenarioAction('exercise', 'injected-source-failure', 900)],
                $inputs,
                $expected,
                false,
                'cold-scenario-suite-cleanup releases exact resources after construction failure',
                true,
            ),
        ];
    }
}
