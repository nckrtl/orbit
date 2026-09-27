<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

/** Evidence captured by Orbit's handoff check for a subtask's diff and command runs. */
final readonly class TaskDeliverableEvidence
{
    private const int MessageTail = 4096;

    /**
     * @param  list<array{status: string, path: string}>|null  $diff
     * @param  array<string, array{exit_code: int, output: string, base_started?: bool, base_exit_code?: int, base_output?: string, base_timed_out?: bool, base_timeout_seconds?: int}>  $commands
     */
    public function __construct(public ?array $diff, public array $commands = []) {}

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
        $commands = [];
        foreach (is_array($data['commands'] ?? null) ? $data['commands'] : [] as $id => $run) {
            if (! is_string($id) || ! is_array($run) || ! is_int($run['exit_code'] ?? null)) {
                continue;
            }
            $commands[$id] = [
                'exit_code' => $run['exit_code'],
                'output' => is_string($run['output'] ?? null) ? $run['output'] : '',
            ];
            if (is_bool($run['base_started'] ?? null)) {
                $commands[$id]['base_started'] = $run['base_started'];
            }
            if (is_int($run['base_exit_code'] ?? null)) {
                $commands[$id]['base_exit_code'] = $run['base_exit_code'];
            }
            if (is_string($run['base_output'] ?? null)) {
                $commands[$id]['base_output'] = mb_substr($run['base_output'], -self::MessageTail);
            }
            if (($run['base_timed_out'] ?? null) === true) {
                $commands[$id]['base_timed_out'] = true;
            }
            if (is_int($run['base_timeout_seconds'] ?? null) && $run['base_timeout_seconds'] > 0) {
                $commands[$id]['base_timeout_seconds'] = $run['base_timeout_seconds'];
            }
        }

        return new self($diff, $commands);
    }

    /**
     * Render base command results for the review handoff.
     *
     * @param  list<TaskDeliverable>  $deliverables
     */
    public function baseRunReview(array $deliverables): string
    {
        $lines = [];
        foreach ($deliverables as $deliverable) {
            if ($deliverable->type !== TaskDeliverableType::Command || ! $deliverable->fails_on_base) {
                continue;
            }
            $run = $this->commands[$deliverable->id] ?? null;
            if ($run === null) {
                continue;
            }
            if (isset($run['base_exit_code'])) {
                $timeout = ($run['base_timed_out'] ?? false) === true
                    ? ' (timed out after '.($run['base_timeout_seconds'] ?? 600).' seconds)'
                    : '';
                $lines[] = '- '.$deliverable->id.': base command exited '.$run['base_exit_code'].$timeout.'; working-tree command exited '.$run['exit_code'].'.';
            }
        }

        return $lines === [] ? '' : "Command runs on the start commit and working tree.\n".implode("\n", $lines);
    }
}
