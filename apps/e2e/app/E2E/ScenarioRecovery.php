<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\State\ScenarioRunStore;
use App\E2E\Value\AttemptId;
use App\E2E\Value\ColdTopologyCleanupResult;
use App\E2E\Value\OperationId;
use App\E2E\Value\ScenarioId;
use App\E2E\Value\ScenarioRunId;
use App\E2E\Value\TopologyRecipe;
use App\E2E\Value\TopologyTarget;
use Closure;
use InvalidArgumentException;

final readonly class ScenarioRecovery
{
    public function __construct(
        private ScenarioRunStore $runs,
        private ColdTopologyConstructor $constructor,
        private ?Closure $cleanup = null,
    ) {}

    public function cleanup(ScenarioRunId $run, ScenarioId $scenario, AttemptId $attempt): ColdTopologyCleanupResult
    {
        if ($this->cleanup !== null) {
            $result = ($this->cleanup)($run, $scenario, $attempt);
            if ($result instanceof ColdTopologyCleanupResult) {
                return $result;
            }

            throw new InvalidArgumentException('The scenario cleanup adapter returned an invalid result.');
        }

        $state = $this->runs->attempt($run, $scenario, $attempt);
        foreach (['run_id', 'scenario_id', 'attempt_id', 'operation_id', 'recipe', 'network', 'instances'] as $key) {
            if (! array_key_exists($key, $state)) {
                throw new InvalidArgumentException('The exact scenario attempt record is incomplete.');
            }
        }
        if (
            $state['run_id'] !== $run->value
            || $state['scenario_id'] !== $scenario->value
            || $state['attempt_id'] !== $attempt->value
            || ! is_string($state['operation_id'])
            || ! is_array($state['recipe'])
            || ! is_string($state['network'])
            || ! is_array($state['instances'])
        ) {
            throw new InvalidArgumentException('The exact scenario attempt identity is invalid.');
        }

        $recipe = TopologyRecipe::fromArray($state['recipe']);
        $target = TopologyTarget::disposableScenario($run, $scenario, $attempt, $recipe);
        $expectedInstances = array_map($target->instance(...), $recipe->nodeKeys());
        if ($state['network'] !== $target->network() || $state['instances'] !== $expectedInstances) {
            throw new InvalidArgumentException('The exact scenario inventory does not match its identities.');
        }

        $cleanup = $this->constructor->cleanup($target, new OperationId($state['operation_id']));

        return new ColdTopologyCleanupResult(
            $cleanup->removed,
            $cleanup->absent,
            $cleanup->refused,
            $cleanup->remaining,
            $this->runs->recoveryCommand($run, $scenario, $attempt),
        );
    }
}
