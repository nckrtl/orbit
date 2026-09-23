<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

/**
 * Checks a subtask's deliverables against its run receipt and Orbit's handoff check
 * ([ADR 0133](/decisions/0133-verify-typed-subtask-deliverables-at-handoff)). Each failure is one sentence
 * that names the deliverable and why it fails.
 */
final readonly class TaskDeliverableVerifier
{
    private const int CasesNamed = 10;

    /**
     * The deliverables a receipt must confirm and does not: every deliverable for the implementer, and every
     * `review` deliverable for the reviewer.
     *
     * @param  list<TaskDeliverable>  $deliverables
     * @param  array<string, string>  $confirmations
     * @return list<string>
     */
    public static function unconfirmed(array $deliverables, array $confirmations, TaskThreadRole $role): array
    {
        $required = array_filter($deliverables, static fn (TaskDeliverable $deliverable): bool => $role === TaskThreadRole::Implementer
            || $deliverable->type === TaskDeliverableType::Review);
        $missing = array_filter($required, static fn (TaskDeliverable $deliverable): bool => trim($confirmations[$deliverable->id] ?? '') === '');

        return array_values(array_map(static fn (TaskDeliverable $deliverable): string => $deliverable->id, $missing));
    }

    /**
     * @param  list<TaskDeliverable>  $deliverables
     * @return list<string>
     */
    public static function failures(array $deliverables, ?TaskDeliverableEvidence $evidence): array
    {
        $checked = array_values(array_filter($deliverables, static fn (TaskDeliverable $deliverable): bool => $deliverable->type !== TaskDeliverableType::Review));
        if ($checked === []) {
            return [];
        }
        if (! $evidence instanceof TaskDeliverableEvidence) {
            return ["Orbit's check recorded no evidence for the deliverables ".implode(', ', array_map(static fn (TaskDeliverable $deliverable): string => $deliverable->id, $checked)).'.'];
        }

        $failures = [];
        foreach ($checked as $deliverable) {
            $reason = match ($deliverable->type) {
                TaskDeliverableType::File => self::file($deliverable, $evidence),
                TaskDeliverableType::Test => self::test($deliverable, $evidence),
                default => self::command($deliverable, $evidence),
            };
            if ($reason !== null) {
                $failures[] = "{$deliverable->id} ({$deliverable->type->value}): {$reason}";
            }
        }

        return $failures;
    }

    private static function file(TaskDeliverable $deliverable, TaskDeliverableEvidence $evidence): ?string
    {
        if ($evidence->diff === null) {
            return "Orbit could not read the subtask's diff.";
        }
        $pattern = TaskDeliverable::relative($deliverable->path);
        $matches = array_values(array_filter($evidence->diff, static fn (array $entry): bool => self::matches($pattern, $entry['path'])));
        $wanted = match ($deliverable->change) {
            'created' => ['A'],
            'modified' => ['M'],
            default => ['A', 'M'],
        };
        foreach ($matches as $entry) {
            if (in_array($entry['status'], $wanted, true)) {
                return null;
            }
        }
        if ($matches === []) {
            return "no path in the subtask's diff matches {$pattern}.";
        }

        return 'the diff '.self::describe($matches[0]).", but the deliverable needs {$pattern} ".($deliverable->change === 'created' ? 'created' : 'modified').'.';
    }

    private static function test(TaskDeliverable $deliverable, TaskDeliverableEvidence $evidence): ?string
    {
        $path = $deliverable->testPath();
        if ($evidence->diff === null) {
            return "Orbit could not read the subtask's diff.";
        }
        $inDiff = array_filter($evidence->diff, static fn (array $entry): bool => $entry['path'] === $path && in_array($entry['status'], ['A', 'M'], true));
        if ($inDiff === []) {
            return "{$path} is not added or modified in the subtask's diff.";
        }
        $run = $evidence->tests[$deliverable->id] ?? null;
        if ($run === null) {
            return "Orbit's check did not run {$path}, so the test has no executed result. A replayed or cached result does not count.";
        }
        $cases = array_values(array_filter($run['cases'], static fn (array $case): bool => str_contains($case['name'], $deliverable->name)));
        if ($cases === []) {
            $names = array_map(static fn (array $case): string => '"'.$case['name'].'"', array_slice($run['cases'], 0, self::CasesNamed));

            return "Orbit ran {$path} (exit code {$run['exit_code']}), and no test name contains \"{$deliverable->name}\"."
                .($names === [] ? ' The run reported no tests.' : ' The run reported '.implode(', ', $names).'.');
        }
        $failed = array_values(array_filter($cases, static fn (array $case): bool => $case['status'] !== 'passed'));
        if ($failed !== []) {
            return 'Orbit ran '.$path.', and '.implode(', ', array_map(static fn (array $case): string => '"'.$case['name'].'" '.$case['status'], $failed)).'.';
        }

        return null;
    }

    private static function command(TaskDeliverable $deliverable, TaskDeliverableEvidence $evidence): ?string
    {
        $run = $evidence->commands[$deliverable->id] ?? null;
        $where = '`'.$deliverable->command.'` in '.(TaskDeliverable::relative($deliverable->directory) ?: '.');
        if ($run === null) {
            return "Orbit's check did not run {$where}.";
        }
        if ($run['exit_code'] === 0) {
            return null;
        }

        return "{$where} exited with {$run['exit_code']}. The end of its output:\n\n```\n".rtrim($run['output'])."\n```\n";
    }

    /** @param array{status: string, path: string} $entry */
    private static function describe(array $entry): string
    {
        $verb = match ($entry['status']) {
            'A' => 'adds',
            'M' => 'modifies',
            'D' => 'deletes',
            default => 'changes ('.$entry['status'].')',
        };

        return "{$verb} {$entry['path']}";
    }

    /**
     * Matches a path against a pattern where `*` stays within one directory, `**` crosses directories,
     * and `?` is one character.
     */
    public static function matches(string $pattern, string $path): bool
    {
        $regex = '';
        $length = strlen($pattern);
        for ($index = 0; $index < $length; $index++) {
            $character = $pattern[$index];
            if ($character === '*' && ($pattern[$index + 1] ?? '') === '*') {
                $index++;
                if (($pattern[$index + 1] ?? '') === '/') {
                    $index++;
                    $regex .= '(?:.*/)?';
                } else {
                    $regex .= '.*';
                }
            } elseif ($character === '*') {
                $regex .= '[^/]*';
            } elseif ($character === '?') {
                $regex .= '[^/]';
            } else {
                $regex .= preg_quote($character, '#');
            }
        }

        return preg_match('#\A'.$regex.'\z#', $path) === 1;
    }
}
