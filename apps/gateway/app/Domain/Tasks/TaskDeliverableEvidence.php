<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

/**
 * What Orbit's handoff check recorded for a subtask's deliverables: the subtask's diff, each test file run,
 * and each command run. A test with fails_on_base also carries the start-commit run (ADR 0163).
 */
final readonly class TaskDeliverableEvidence
{
    private const int MessageTail = 4096;

    /**
     * @param  list<array{status: string, path: string}>|null  $diff  null when the check could not read the diff
     * @param  array<string, array{exit_code: int, cases: list<array{name: string, status: string, kind?: string, message?: string}>, base_placed?: bool, base_exit_code?: int, base_timed_out?: bool, base_timeout_seconds?: int, base_cases?: list<array{name: string, status: string, kind?: string, message?: string}>}>  $tests  by deliverable ID
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
            if (($run['base_timed_out'] ?? null) === true) {
                $tests[$id]['base_timed_out'] = true;
            }
            if (is_int($run['base_timeout_seconds'] ?? null) && $run['base_timeout_seconds'] > 0) {
                $tests[$id]['base_timeout_seconds'] = $run['base_timeout_seconds'];
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
     * The lines the review request shows for a base run, so an error is not read as an assertion failure (ADR 0163).
     *
     * @param  list<TaskDeliverable>  $deliverables
     */
    public function baseRunReview(array $deliverables): string
    {
        $lines = [];
        foreach ($deliverables as $deliverable) {
            if ($deliverable->type !== TaskDeliverableType::Test || ! $deliverable->fails_on_base) {
                continue;
            }
            $run = $this->tests[$deliverable->id] ?? null;
            if ($run === null) {
                continue;
            }
            if (($run['base_timed_out'] ?? false) === true && is_int($run['base_timeout_seconds'] ?? null)) {
                $lines[] = '- '.$deliverable->id.': The base run timed out after '.$run['base_timeout_seconds'].' seconds, so it counts as failing on the start commit.';
            }
            foreach ($run['base_cases'] ?? [] as $case) {
                if ($case['status'] !== 'failed') {
                    continue;
                }
                $kind = ($case['kind'] ?? '') === 'error' ? 'an error' : 'a failure';
                $message = trim($case['message'] ?? '');
                $lines[] = $message === ''
                    ? '- '.$deliverable->id.': "'.$case['name'].'" failed on the start commit with '.$kind.'.'
                    : '- '.$deliverable->id.': "'.$case['name'].'" failed on the start commit with '.$kind.': '.$message;
            }
        }
        if ($lines === []) {
            return '';
        }

        return "Base run on the start commit. An error, such as a missing class, is not an assertion failure.\n".implode("\n", $lines);
    }

    /**
     * @return list<array{name: string, status: string, kind?: string, message?: string}>
     */
    private static function cases(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $cases = [];
        foreach ($value as $case) {
            if (! is_array($case) || ! is_string($case['name'] ?? null) || ! is_string($case['status'] ?? null)) {
                continue;
            }
            $record = ['name' => $case['name'], 'status' => $case['status']];
            if (in_array($case['kind'] ?? null, ['failure', 'error'], true)) {
                $record['kind'] = $case['kind'];
            }
            if (is_string($case['message'] ?? null)) {
                $record['message'] = mb_substr($case['message'], -self::MessageTail);
            }
            $cases[] = $record;
        }

        return $cases;
    }
}
