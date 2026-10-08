<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

/**
 * Checks a subtask's deliverables against its turn receipt and Orbit's handoff check
 * ([ADR 0133](/decisions/0133-verify-typed-subtask-deliverables-at-handoff),
 * [ADR 0163](/decisions/0163-prove-a-failing-test-on-the-start-commit)). Each failure is one sentence
 * that names the deliverable and why it fails.
 */
final readonly class TaskDeliverableVerifier
{
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

    private static function command(TaskDeliverable $deliverable, TaskDeliverableEvidence $evidence): ?string
    {
        $run = $evidence->commands[$deliverable->id] ?? null;
        $where = '`'.$deliverable->command.'` in '.(TaskDeliverable::relative($deliverable->directory) ?: '.');
        if ($run === null) {
            return "Orbit's check did not run {$where}.";
        }
        if ($run['exit_code'] !== 0) {
            return "{$where} exited with {$run['exit_code']}. The end of its output:\n\n```\n".rtrim($run['output'])."\n```\n";
        }
        if ($deliverable->fails_on_base) {
            if (($run['base_started'] ?? false) !== true) {
                return "Orbit could not run {$where} on the start commit.";
            }
            if (! isset($run['base_exit_code'])) {
                return "Orbit did not run {$where} on the start commit.";
            }
            if (in_array($run['base_exit_code'], [126, 127], true)) {
                return "Orbit could not run {$where} on the start commit (exit {$run['base_exit_code']}).";
            }
            if (($run['base_timed_out'] ?? false) !== true && $run['base_exit_code'] === 0) {
                return "{$where} also exited 0 on the start commit, so it does not reproduce the failure.";
            }
        }

        return null;
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
     * `?` is one character, and `{a,b}` is a non-nested alternative, including `{php}`.
     */
    public static function matches(string $pattern, string $path): bool
    {
        return preg_match('#\A'.self::toRegex($pattern).'\z#', $path) === 1;
    }

    private static function toRegex(string $pattern): string
    {
        $regex = '';
        $length = strlen($pattern);
        for ($index = 0; $index < $length; $index++) {
            $character = $pattern[$index];
            if ($character === '{') {
                $group = self::braceAlternation($pattern, $index);
                if ($group !== null) {
                    $regex .= '(?:'.implode('|', array_map(self::toRegex(...), $group['alternatives'])).')';
                    $index = $group['end'];

                    continue;
                }
            }
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

        return $regex;
    }

    /**
     * A non-nested `{a,b,...}` group, including a single alternative such as `{php}`. Nested braces stay literal.
     *
     * @return array{end: int, alternatives: list<string>}|null
     */
    private static function braceAlternation(string $pattern, int $open): ?array
    {
        $length = strlen($pattern);
        $inside = '';
        for ($index = $open + 1; $index < $length; $index++) {
            $character = $pattern[$index];
            if ($character === '{') {
                return null;
            }
            if ($character === '}') {
                return [
                    'end' => $index,
                    'alternatives' => explode(',', $inside),
                ];
            }
            $inside .= $character;
        }

        return null;
    }
}
