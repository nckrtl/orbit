<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

/**
 * What Orbit's handoff check recorded for a subtask's deliverables: the subtask's diff, each test file run,
 * and each command run.
 */
final readonly class TaskDeliverableEvidence
{
    /**
     * @param  list<array{status: string, path: string}>|null  $diff  null when the check could not read the diff
     * @param  array<string, array{exit_code: int, cases: list<array{name: string, status: string}>}>  $tests  by deliverable ID
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
            $cases = [];
            foreach (is_array($run['cases'] ?? null) ? $run['cases'] : [] as $case) {
                if (is_array($case) && is_string($case['name'] ?? null) && is_string($case['status'] ?? null)) {
                    $cases[] = ['name' => $case['name'], 'status' => $case['status']];
                }
            }
            $tests[$id] = ['exit_code' => $run['exit_code'], 'cases' => $cases];
        }
        $commands = [];
        foreach (is_array($data['commands'] ?? null) ? $data['commands'] : [] as $id => $run) {
            if (is_string($id) && is_array($run) && is_int($run['exit_code'] ?? null)) {
                $commands[$id] = ['exit_code' => $run['exit_code'], 'output' => is_string($run['output'] ?? null) ? $run['output'] : ''];
            }
        }

        return new self($diff, $tests, $commands);
    }
}
