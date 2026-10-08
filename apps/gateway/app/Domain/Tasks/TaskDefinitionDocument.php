<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

/**
 * The stored shape of a definition that has already passed validation.
 */
final readonly class TaskDefinitionDocument
{
    /**
     * @param  array<string, mixed>  $definition
     * @return array{
     *     name: string,
     *     title: string,
     *     brief: string,
     *     parameters: list<array<string, mixed>>,
     *     status: string,
     *     schedule: array<string, mixed>|null,
     *     phases: list<array<string, mixed>>,
     *     subtasks: list<array<string, mixed>>
     * }
     */
    public function normalize(array $definition): array
    {
        $name = $definition['name'] ?? '';
        $title = $definition['title'] ?? '';
        $brief = $definition['brief'] ?? '';
        $status = $definition['status'] ?? '';

        return [
            'name' => is_string($name) ? $name : '',
            'title' => is_string($title) ? $title : '',
            'brief' => is_string($brief) ? $brief : '',
            'parameters' => $this->parameters(is_array($definition['parameters'] ?? null) ? $definition['parameters'] : []),
            'status' => is_string($status) ? $status : '',
            'schedule' => $this->schedule($definition['schedule'] ?? null),
            'phases' => $this->phases(is_array($definition['phases'] ?? null) ? $definition['phases'] : []),
            'subtasks' => $this->subtasks(is_array($definition['subtasks'] ?? null) ? $definition['subtasks'] : []),
        ];
    }

    /**
     * @param  array<mixed>  $parameters
     * @return list<array<string, mixed>>
     */
    private function parameters(array $parameters): array
    {
        $stored = [];

        foreach ($parameters as $parameter) {
            if (! is_array($parameter)) {
                continue;
            }

            $name = $parameter['name'] ?? null;
            $type = $parameter['type'] ?? null;
            $required = $parameter['required'] ?? null;

            if (! is_string($name) || ! is_string($type) || ! is_bool($required)) {
                continue;
            }

            $row = ['name' => $name, 'type' => $type, 'required' => $required];

            if (array_key_exists('default', $parameter)) {
                $row['default'] = $parameter['default'];
            }

            $stored[] = $row;
        }

        return $stored;
    }

    /** @return array<string, mixed>|null */
    private function schedule(mixed $schedule): ?array
    {
        if (! is_array($schedule)) {
            return null;
        }

        $cron = $schedule['cron'] ?? null;
        $canonical = is_string($cron) ? TaskDefinitionCron::canonical($cron) : null;
        $values = [];
        $given = $schedule['values'] ?? [];

        if (is_array($given)) {
            foreach ($given as $name => $value) {
                if (is_string($name)) {
                    $values[$name] = $value;
                }
            }
        }

        return [
            'cron' => $canonical ?? (is_string($cron) ? trim($cron) : ''),
            'values' => $values,
        ];
    }

    /**
     * @param  array<mixed>  $phases
     * @return list<array<string, mixed>>
     */
    private function phases(array $phases): array
    {
        $stored = [];

        foreach ($phases as $phase) {
            if (! is_array($phase)) {
                continue;
            }

            $key = $phase['key'] ?? null;
            $title = $phase['title'] ?? null;
            $brief = $phase['brief'] ?? null;
            $repeat = $phase['repeat'] ?? null;

            if (! is_string($key) || ! is_string($title) || ! is_string($brief) || ! is_bool($repeat)) {
                continue;
            }

            $stored[] = [
                'key' => $key,
                'title' => $title,
                'brief' => $brief,
                'repeat' => $repeat,
            ];
        }

        return $stored;
    }

    /**
     * @param  array<mixed>  $subtasks
     * @return list<array<string, mixed>>
     */
    private function subtasks(array $subtasks): array
    {
        $stored = [];

        foreach ($subtasks as $subtask) {
            if (! is_array($subtask)) {
                continue;
            }

            $key = $subtask['key'] ?? null;
            $title = $subtask['title'] ?? null;
            $kind = $subtask['kind'] ?? null;

            if (! is_string($key) || ! is_string($title) || ! is_string($kind)) {
                continue;
            }

            $row = ['key' => $key, 'title' => $title, 'kind' => $kind];
            $this->copyString($row, $subtask, 'brief');
            $this->copyString($row, $subtask, 'phase');
            $this->copyString($row, $subtask, 'implementer_model');
            $this->copyString($row, $subtask, 'reviewer_model');
            $this->copyString($row, $subtask, 'operation');
            $this->copyString($row, $subtask, 'question');

            if (array_key_exists('topology', $subtask)) {
                $row['topology'] = TaskTopology::from($subtask['topology']);
            }

            if (isset($subtask['deliverables']) && is_array($subtask['deliverables']) && $subtask['deliverables'] !== []) {
                $row['deliverables'] = array_values(array_map(
                    static fn (mixed $deliverable): array => TaskDeliverable::fromArray(is_array($deliverable) ? $deliverable : [])->toArray(),
                    $subtask['deliverables'],
                ));
            }

            if (isset($subtask['routes']) && is_array($subtask['routes'])) {
                $routes = [];

                foreach ($subtask['routes'] as $outcome => $target) {
                    if (is_string($outcome) && is_string($target)) {
                        $routes[$outcome] = $target;
                    }
                }

                if ($routes !== []) {
                    $row['routes'] = $routes;
                }
            }

            if (array_key_exists('arguments', $subtask) && is_array($subtask['arguments'])) {
                $row['arguments'] = $subtask['arguments'];
            }

            if (isset($subtask['options']) && is_array($subtask['options'])) {
                $row['options'] = array_values(array_filter($subtask['options'], is_string(...)));
            }

            if (isset($subtask['evidence']) && is_array($subtask['evidence'])) {
                $row['evidence'] = array_values(array_filter($subtask['evidence'], is_string(...)));
            }

            if (array_key_exists('min_probability', $subtask) && (is_int($subtask['min_probability']) || is_float($subtask['min_probability']))) {
                $row['min_probability'] = $subtask['min_probability'];
            }

            $stored[] = $row;
        }

        return $stored;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<mixed>  $subtask
     */
    private function copyString(array &$row, array $subtask, string $field): void
    {
        $value = $subtask[$field] ?? null;

        if (is_string($value) && $value !== '') {
            $row[$field] = $value;
        }
    }
}
