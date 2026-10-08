<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

/**
 * Refuses a task definition that breaks a documented rule.
 * Details name the rule and, when the rule is about one subtask, its key.
 */
final readonly class TaskDefinitionValidator
{
    /** @var list<string> */
    private const array CommonFields = ['key', 'title', 'kind', 'brief', 'phase', 'deliverables', 'routes', 'topology'];

    /** @var list<string> */
    private const array ReservedKeys = ['complete', 'fail'];

    private const int MaxSubtasks = 100;

    private const int MaxParameters = 50;

    private const int MaxPhases = 50;

    private const int MaxArguments = 50;

    private const int MaxScheduleValues = 100;

    public function __construct(private OpenApiTaskActions $actions) {}

    /**
     * @param  array<string, mixed>  $definition
     * @return list<TaskDefinitionViolation>
     */
    public function validate(array $definition): array
    {
        $subtasks = $this->records($definition['subtasks'] ?? null);
        $parameters = $this->records($definition['parameters'] ?? null);
        $phases = $this->records($definition['phases'] ?? null);

        return $this->unique([
            ...$this->duplicateKeys($subtasks),
            ...$this->kinds($subtasks),
            ...$this->fields($subtasks),
            ...$this->routeOutcomes($subtasks),
            ...$this->routeTargets($subtasks),
            ...$this->decideRoutes($subtasks),
            ...$this->reachability($subtasks),
            ...$this->phaseRules($subtasks, $phases),
            ...$this->actions($subtasks),
            ...$this->parameterRules($definition, $parameters),
            ...$this->scheduleNames($definition, $parameters),
            ...$this->cron($definition),
            ...$this->scheduleValues($definition, $parameters),
            ...$this->duplicatePhaseKeys($phases),
            ...$this->bounds($definition, $subtasks, $parameters, $phases),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $subtasks
     * @return list<TaskDefinitionViolation>
     */
    private function duplicateKeys(array $subtasks): array
    {
        $counts = [];

        foreach ($subtasks as $subtask) {
            $key = $this->text($subtask, 'key');

            if ($key === '') {
                continue;
            }

            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        $violations = [];

        foreach ($counts as $key => $count) {
            if ($count > 1 || in_array($key, self::ReservedKeys, true)) {
                $violations[] = new TaskDefinitionViolation('keys', $key);
            }
        }

        return $violations;
    }

    /**
     * @param  list<array<string, mixed>>  $subtasks
     * @return list<TaskDefinitionViolation>
     */
    private function kinds(array $subtasks): array
    {
        $violations = [];

        foreach ($subtasks as $subtask) {
            if ($this->kind($subtask) instanceof TaskDefinitionKind) {
                continue;
            }

            $violations[] = new TaskDefinitionViolation('kind', $this->text($subtask, 'key'));
        }

        return $violations;
    }

    /**
     * @param  list<array<string, mixed>>  $subtasks
     * @return list<TaskDefinitionViolation>
     */
    private function fields(array $subtasks): array
    {
        $violations = [];

        foreach ($subtasks as $index => $subtask) {
            $kind = $this->kind($subtask);

            if (! $kind instanceof TaskDefinitionKind) {
                continue;
            }

            if ($this->fieldProblem($subtask, $kind, $index, $subtasks)) {
                $violations[] = new TaskDefinitionViolation('fields', $this->text($subtask, 'key'));
            }
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $subtask
     * @param  list<array<string, mixed>>  $subtasks
     */
    private function fieldProblem(array $subtask, TaskDefinitionKind $kind, int $index, array $subtasks): bool
    {
        $allowed = [...self::CommonFields, ...$kind->requiredFields(), ...$kind->optionalFields()];

        foreach (array_keys($subtask) as $field) {
            if (! in_array($field, $allowed, true)) {
                return true;
            }
        }

        foreach ($kind->requiredFields() as $field) {
            if (! array_key_exists($field, $subtask) || $this->missingRequired($field, $subtask[$field])) {
                return true;
            }
        }

        if (array_key_exists('topology', $subtask) && ! TaskTopology::valid($subtask['topology'])) {
            return true;
        }

        if ($kind->requiresCommandDeliverable() && ! $this->hasCommandDeliverable($subtask)) {
            return true;
        }

        if ($kind === TaskDefinitionKind::Decide) {
            $options = $this->names($subtask['options'] ?? null);

            if ($options === null || $options === [] || count($options) !== count(array_unique($options))) {
                return true;
            }

            if (! $this->evidenceNamesEarlier($subtask, $index, $subtasks)) {
                return true;
            }
        }

        if (array_key_exists('min_probability', $subtask) && ! $this->probability($subtask['min_probability'])) {
            return true;
        }

        return $kind === TaskDefinitionKind::Agent && $this->emptyModel($subtask);
    }

    /** @param array<string, mixed> $subtask */
    private function emptyModel(array $subtask): bool
    {
        foreach (['implementer_model', 'reviewer_model'] as $field) {
            if (! array_key_exists($field, $subtask)) {
                continue;
            }

            $model = $subtask[$field];

            if (! is_string($model) || $model === '') {
                return true;
            }
        }

        return false;
    }

    private function missingRequired(string $field, mixed $value): bool
    {
        return match ($field) {
            'operation', 'question' => ! is_string($value) || $value === '',
            'arguments' => ! $this->isObject($value),
            'options' => ! is_array($value),
            'evidence' => ! is_array($value),
            default => $value === null,
        };
    }

    /**
     * @param  array<string, mixed>  $subtask
     * @param  list<array<string, mixed>>  $subtasks
     */
    private function evidenceNamesEarlier(array $subtask, int $index, array $subtasks): bool
    {
        $evidence = $subtask['evidence'] ?? null;

        if (! is_array($evidence)) {
            return false;
        }

        return array_all(
            $evidence,
            fn (mixed $reference): bool => is_string($reference) && $this->earlierIndex($reference, $index, $subtasks) !== null,
        );
    }

    /**
     * @param  array<string, mixed>  $subtask
     */
    private function hasCommandDeliverable(array $subtask): bool
    {
        $deliverables = $subtask['deliverables'] ?? null;

        if (! is_array($deliverables)) {
            return false;
        }

        return array_any(
            $deliverables,
            fn (mixed $deliverable): bool => is_array($deliverable) && ($deliverable['type'] ?? null) === TaskDeliverableType::Command->value,
        );
    }

    private function probability(mixed $value): bool
    {
        if (! is_int($value) && ! is_float($value)) {
            return false;
        }

        return $value >= 0 && $value <= 1;
    }

    /**
     * @param  list<array<string, mixed>>  $subtasks
     * @return list<TaskDefinitionViolation>
     */
    private function routeOutcomes(array $subtasks): array
    {
        $violations = [];

        foreach ($subtasks as $subtask) {
            $kind = $this->kind($subtask);
            $routes = $this->routeMap($subtask);

            if (! $kind instanceof TaskDefinitionKind || $routes === null) {
                continue;
            }

            $outcomes = $this->declaredOutcomes($subtask, $kind);

            if ($outcomes === null) {
                continue;
            }

            foreach (array_keys($routes) as $outcome) {
                if (! in_array($outcome, $outcomes, true)) {
                    $violations[] = new TaskDefinitionViolation('route_outcome', $this->text($subtask, 'key'));

                    break;
                }
            }
        }

        return $violations;
    }

    /**
     * @param  list<array<string, mixed>>  $subtasks
     * @return list<TaskDefinitionViolation>
     */
    private function routeTargets(array $subtasks): array
    {
        $violations = [];

        foreach ($subtasks as $index => $subtask) {
            $kind = $this->kind($subtask);
            $routes = $this->routeMap($subtask);

            if (! $kind instanceof TaskDefinitionKind || $routes === null) {
                continue;
            }

            $outcomes = $this->declaredOutcomes($subtask, $kind);

            if ($outcomes === null) {
                continue;
            }

            foreach ($routes as $outcome => $target) {
                if (! in_array($outcome, $outcomes, true)) {
                    continue;
                }

                if (! $this->validTarget($target, $index, $subtasks)) {
                    $violations[] = new TaskDefinitionViolation('route_target', $this->text($subtask, 'key'));

                    break;
                }
            }
        }

        return $violations;
    }

    /**
     * @param  list<array<string, mixed>>  $subtasks
     * @return list<TaskDefinitionViolation>
     */
    private function decideRoutes(array $subtasks): array
    {
        $violations = [];

        foreach ($subtasks as $subtask) {
            if ($this->kind($subtask) !== TaskDefinitionKind::Decide) {
                continue;
            }

            $options = $this->names($subtask['options'] ?? null);
            $routes = $this->routeMap($subtask);

            if ($options === null || $options === [] || $routes === null) {
                continue;
            }

            foreach ($options as $option) {
                if (! array_key_exists($option, $routes)) {
                    $violations[] = new TaskDefinitionViolation('decide_routes', $this->text($subtask, 'key'));

                    break;
                }
            }
        }

        return $violations;
    }

    /**
     * @param  list<array<string, mixed>>  $subtasks
     */
    private function validTarget(mixed $target, int $index, array $subtasks): bool
    {
        if (! is_string($target)) {
            return false;
        }

        if ($target === 'complete' || $target === 'fail') {
            return true;
        }

        return $this->laterIndex($target, $index, $subtasks) !== null;
    }

    /**
     * @param  list<array<string, mixed>>  $subtasks
     * @return list<TaskDefinitionViolation>
     */
    private function reachability(array $subtasks): array
    {
        $count = count($subtasks);

        if ($count === 0) {
            return [];
        }

        $edges = [];

        foreach ($subtasks as $index => $subtask) {
            $edges[$index] = $this->forwardEdges($subtask, $index, $subtasks);
        }

        $seen = [0 => true];
        $pending = [0];

        while ($pending !== []) {
            $index = array_pop($pending);

            foreach ($edges[$index] as $next) {
                if (isset($seen[$next])) {
                    continue;
                }

                $seen[$next] = true;
                $pending[] = $next;
            }
        }

        $violations = [];

        foreach ($subtasks as $index => $subtask) {
            if ($index === 0 || isset($seen[$index])) {
                continue;
            }

            $violations[] = new TaskDefinitionViolation('reachability', $this->text($subtask, 'key'));
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $subtask
     * @param  list<array<string, mixed>>  $subtasks
     * @return list<int>
     */
    private function forwardEdges(array $subtask, int $index, array $subtasks): array
    {
        $kind = $this->kind($subtask);

        if (! $kind instanceof TaskDefinitionKind) {
            return [];
        }

        $outcomes = $this->declaredOutcomes($subtask, $kind);
        $routes = $this->routeMap($subtask) ?? [];

        if ($outcomes === null) {
            return [];
        }

        $edges = [];

        foreach ($outcomes as $outcome) {
            if (array_key_exists($outcome, $routes)) {
                $target = $routes[$outcome];
                $later = is_string($target) ? $this->laterIndex($target, $index, $subtasks) : null;

                if ($later !== null) {
                    $edges[] = $later;
                }

                continue;
            }

            if ($kind !== TaskDefinitionKind::Decide && $outcome === 'passed' && isset($subtasks[$index + 1])) {
                $edges[] = $index + 1;
            }
        }

        return $edges;
    }

    /**
     * @param  list<array<string, mixed>>  $subtasks
     * @param  list<array<string, mixed>>  $phases
     * @return list<TaskDefinitionViolation>
     */
    private function phaseRules(array $subtasks, array $phases): array
    {
        $known = [];

        foreach ($phases as $phase) {
            $key = $this->text($phase, 'key');

            if ($key !== '') {
                $known[$key] = true;
            }
        }

        $positions = [];
        $violations = [];

        foreach ($subtasks as $index => $subtask) {
            if (! array_key_exists('phase', $subtask)) {
                continue;
            }

            $phase = $subtask['phase'];

            if (! is_string($phase) || $phase === '' || ! isset($known[$phase])) {
                $violations[] = new TaskDefinitionViolation('phases', $this->text($subtask, 'key'));

                continue;
            }

            $positions[$phase][] = $index;
        }

        foreach ($positions as $indices) {
            $span = range($indices[0], $indices[count($indices) - 1]);

            if ($indices === $span) {
                continue;
            }

            foreach ($indices as $index) {
                $violations[] = new TaskDefinitionViolation('phases', $this->text($subtasks[$index], 'key'));
            }
        }

        return $violations;
    }

    /**
     * @param  list<array<string, mixed>>  $phases
     * @return list<TaskDefinitionViolation>
     */
    private function duplicatePhaseKeys(array $phases): array
    {
        $counts = [];

        foreach ($phases as $phase) {
            $key = $this->text($phase, 'key');

            if ($key === '') {
                continue;
            }

            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        foreach ($counts as $count) {
            if ($count > 1) {
                return [new TaskDefinitionViolation('phase_keys', null)];
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  list<array<string, mixed>>  $subtasks
     * @param  list<array<string, mixed>>  $parameters
     * @param  list<array<string, mixed>>  $phases
     * @return list<TaskDefinitionViolation>
     */
    private function bounds(array $definition, array $subtasks, array $parameters, array $phases): array
    {
        $violations = [];

        if (
            count($subtasks) > self::MaxSubtasks
            || count($parameters) > self::MaxParameters
            || count($phases) > self::MaxPhases
            || $this->scheduleValueCount($definition) > self::MaxScheduleValues
        ) {
            $violations[] = new TaskDefinitionViolation('bounds', null);
        }

        foreach ($subtasks as $subtask) {
            if ($this->argumentCount($subtask) > self::MaxArguments) {
                $violations[] = new TaskDefinitionViolation('bounds', $this->text($subtask, 'key'));
            }
        }

        return $violations;
    }

    /** @param array<string, mixed> $subtask */
    private function argumentCount(array $subtask): int
    {
        $arguments = $subtask['arguments'] ?? null;

        return is_array($arguments) ? count($arguments) : 0;
    }

    /** @param array<string, mixed> $definition */
    private function scheduleValueCount(array $definition): int
    {
        if (! array_key_exists('schedule', $definition) || ! is_array($definition['schedule'])) {
            return 0;
        }

        $values = $definition['schedule']['values'] ?? null;

        return is_array($values) ? count($values) : 0;
    }

    /**
     * @param  list<array<string, mixed>>  $subtasks
     * @return list<TaskDefinitionViolation>
     */
    private function actions(array $subtasks): array
    {
        $violations = [];

        foreach ($subtasks as $subtask) {
            if ($this->kind($subtask) !== TaskDefinitionKind::Action) {
                continue;
            }

            $operation = $subtask['operation'] ?? null;

            if (! is_string($operation) || $operation === '' || $this->actions->allows($operation)) {
                continue;
            }

            $violations[] = new TaskDefinitionViolation('action', $this->text($subtask, 'key'));
        }

        return $violations;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  list<array<string, mixed>>  $parameters
     * @return list<TaskDefinitionViolation>
     */
    private function parameterRules(array $definition, array $parameters): array
    {
        $names = [];
        $duplicate = false;
        $subtasksParameters = 0;

        foreach ($parameters as $parameter) {
            $name = $this->text($parameter, 'name');

            if ($name === '') {
                continue;
            }

            if (isset($names[$name])) {
                $duplicate = true;
            }

            $names[$name] = true;

            if (($parameter['type'] ?? null) === 'subtasks') {
                $subtasksParameters++;
            }
        }

        $title = $definition['title'] ?? '';
        $brief = $definition['brief'] ?? '';
        $text = (is_string($title) ? $title : '')."\n".(is_string($brief) ? $brief : '');
        $undeclared = false;

        if (preg_match_all('/\{([a-z0-9]+(?:-[a-z0-9]+)*)\}/', $text, $matches) !== false) {
            foreach ($matches[1] as $placeholder) {
                if (! isset($names[$placeholder])) {
                    $undeclared = true;
                }
            }
        }

        if ($duplicate || $subtasksParameters > 1 || $undeclared) {
            return [new TaskDefinitionViolation('parameters', null)];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  list<array<string, mixed>>  $parameters
     * @return list<TaskDefinitionViolation>
     */
    private function scheduleNames(array $definition, array $parameters): array
    {
        $values = $this->scheduleValuesMap($definition);

        if ($values === null) {
            return [];
        }

        $names = [];

        foreach ($parameters as $parameter) {
            $name = $this->text($parameter, 'name');

            if ($name !== '') {
                $names[$name] = true;
            }
        }

        foreach (array_keys($values) as $name) {
            if (! isset($names[$name])) {
                return [new TaskDefinitionViolation('schedule_names', null)];
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return list<TaskDefinitionViolation>
     */
    private function cron(array $definition): array
    {
        $schedule = $this->schedule($definition);

        if ($schedule === null) {
            return [];
        }

        $cron = $schedule['cron'] ?? null;

        if (! is_string($cron) || TaskDefinitionCron::canonical($cron) === null) {
            return [new TaskDefinitionViolation('cron', null)];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  list<array<string, mixed>>  $parameters
     * @return list<TaskDefinitionViolation>
     */
    private function scheduleValues(array $definition, array $parameters): array
    {
        $values = $this->scheduleValuesMap($definition);

        if ($values === null) {
            return [];
        }

        foreach ($parameters as $parameter) {
            if (($parameter['required'] ?? false) !== true) {
                continue;
            }

            $name = $this->text($parameter, 'name');

            if ($name === '' || ! array_key_exists($name, $values) || $values[$name] === null) {
                return [new TaskDefinitionViolation('schedule_values', null)];
            }
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>|null
     */
    private function schedule(array $definition): ?array
    {
        if (! array_key_exists('schedule', $definition) || $definition['schedule'] === null) {
            return null;
        }

        return is_array($definition['schedule']) ? $this->stringKeyed($definition['schedule']) : null;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>|null
     */
    private function scheduleValuesMap(array $definition): ?array
    {
        $schedule = $this->schedule($definition);

        if ($schedule === null) {
            return null;
        }

        $raw = $schedule['values'] ?? [];

        if (! is_array($raw)) {
            return [];
        }

        foreach (array_keys($raw) as $name) {
            if (! is_string($name)) {
                return ['__list__' => true];
            }
        }

        return $this->stringKeyed($raw);
    }

    /**
     * @param  array<string, mixed>  $subtask
     * @return list<string>|null
     */
    private function declaredOutcomes(array $subtask, TaskDefinitionKind $kind): ?array
    {
        if ($kind !== TaskDefinitionKind::Decide) {
            return $kind->outcomes();
        }

        return $this->names($subtask['options'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $subtask
     * @return array<string, mixed>|null
     */
    private function routeMap(array $subtask): ?array
    {
        if (! array_key_exists('routes', $subtask)) {
            return [];
        }

        $routes = $subtask['routes'];

        if (! is_array($routes)) {
            return null;
        }

        foreach (array_keys($routes) as $outcome) {
            if (! is_string($outcome)) {
                return ['' => $routes[$outcome]];
            }
        }

        return $this->stringKeyed($routes);
    }

    /** @param array<string, mixed> $subtask */
    private function kind(array $subtask): ?TaskDefinitionKind
    {
        $kind = $subtask['kind'] ?? null;

        return is_string($kind) ? TaskDefinitionKind::tryFrom($kind) : null;
    }

    /** @param array<string, mixed> $record */
    private function text(array $record, string $field): string
    {
        $value = $record[$field] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * @param  list<array<string, mixed>>  $subtasks
     */
    private function earlierIndex(string $key, int $index, array $subtasks): ?int
    {
        foreach ($subtasks as $candidate => $subtask) {
            if ($candidate < $index && $this->text($subtask, 'key') === $key) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  list<array<string, mixed>>  $subtasks
     */
    private function laterIndex(string $key, int $index, array $subtasks): ?int
    {
        foreach ($subtasks as $candidate => $subtask) {
            if ($candidate > $index && $this->text($subtask, 'key') === $key) {
                return $candidate;
            }
        }

        return null;
    }

    /** @return list<string>|null */
    private function names(mixed $value): ?array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return null;
        }

        $names = [];

        foreach ($value as $item) {
            if (! is_string($item) || $item === '') {
                return null;
            }

            $names[] = $item;
        }

        return $names;
    }

    private function isObject(mixed $value): bool
    {
        return is_array($value) && ($value === [] || ! array_is_list($value));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function records(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $records = [];

        foreach ($value as $record) {
            if (is_array($record)) {
                $records[] = $this->stringKeyed($record);
            }
        }

        return $records;
    }

    /**
     * @param  array<mixed>  $value
     * @return array<string, mixed>
     */
    private function stringKeyed(array $value): array
    {
        $keyed = [];

        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $keyed[$key] = $item;
            }
        }

        return $keyed;
    }

    /**
     * @param  list<TaskDefinitionViolation>  $violations
     * @return list<TaskDefinitionViolation>
     */
    private function unique(array $violations): array
    {
        $seen = [];
        $unique = [];

        foreach ($violations as $violation) {
            $id = $violation->rule."\0".($violation->subtask ?? '');

            if (isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $unique[] = $violation;
        }

        return $unique;
    }
}
