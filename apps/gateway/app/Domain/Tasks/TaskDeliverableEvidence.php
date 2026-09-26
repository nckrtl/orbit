<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

/**
 * What Orbit's handoff check recorded for a subtask's deliverables: the subtask's diff, each test file run,
 * and each command run. A test with fails_on_base also carries the start-commit run (ADR 0163).
 */
final readonly class TaskDeliverableEvidence
{
    /**
     * @param  list<array{status: string, path: string}>|null  $diff  null when the check could not read the diff
     * @param  array<string, array{exit_code: int, cases: list<array{name: string, status: string}>, base_placed?: bool, base_exit_code?: int, base_cases?: list<array{name: string, status: string}>}>  $tests  by deliverable ID
     * @param  array<string, array{exit_code: int, output: string}>  $commands  by deliverable ID
     */
    public function __construct(
        public ?array $diff,
        public array $tests = [],
        public array $commands = [],
    ) {}

    public static function fromArray(mixed $data): ?self
    {
        if (! is_array($data)) {
            return null;
        }
        $diff = null;
        if (is_array($data['diff'] ?? null)) {
            $diff = [];
            foreach ($data['diff'] as $entry) {
                if (is_array($entry) && is_string($entry['status'] ?? null) && is_string($entry['path'] ?? null)) {
                    $diff[] = ['status' => $entry['status'], 'path' => $entry['path']];
                }
            }
        }
        $tests = [];
        foreach (is_array($data['tests'] ?? null) ? $data['tests'] : [] as $id => $run) {
            if (! is_string($id) || ! is_array($run) || ! is_int($run['exit_code'] ?? null)) {
                continue;
            }
            $tests[$id] = ['exit_code' => $run['exit_code'], 'cases' => self::cases($run['cases'] ?? null)];
            if (array_key_exists('base_placed', $run)) {
                $tests[$id]['base_placed'] = $run['base_placed'] === true;
            }
            if (is_int($run['base_exit_code'] ?? null)) {
                $tests[$id]['base_exit_code'] = $run['base_exit_code'];
            }
            if (is_array($run['base_cases'] ?? null)) {
                $tests[$id]['base_cases'] = self::cases($run['base_cases']);
            }
        }
        $commands = [];
        foreach (is_array($data['commands'] ?? null) ? $data['commands'] : [] as $id => $run) {
            if (is_string($id) && is_array($run) && is_int($run['exit_code'] ?? null)) {
                $commands[$id] = ['exit_code' => $run['exit_code'], 'output' => is_string($run['output'] ?? null) ? $run['output'] : ''];
            }
        }

        return new self($diff, $tests, $commands);
    }

    /**
     * @return list<array{name: string, status: string}>
     */
    private static function cases(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $cases = [];
        foreach ($value as $case) {
            if (is_array($case) && is_string($case['name'] ?? null) && is_string($case['status'] ?? null)) {
                $cases[] = ['name' => $case['name'], 'status' => $case['status']];
            }
        }

        return $cases;
    }
}
