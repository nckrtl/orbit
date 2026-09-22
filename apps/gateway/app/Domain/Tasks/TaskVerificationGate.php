<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Shared\ResourceOperationException;
use App\Models\AppInstance;
use App\Models\Task;
use App\Models\TaskVerification;

final readonly class TaskVerificationGate
{
    public function __construct(private TaskCheckRunner $runner, private TaskVerificationPolicy $policy) {}

    public function latest(Task $task): ?TaskVerification
    {
        return TaskVerification::query()->where('task_id', $task->id)->latest('id')->first();
    }

    public function busy(Task $task): bool
    {
        if (! $task->verification_required) {
            return false;
        }
        $run = $this->latest($task);
        if ($run === null || $run->status !== 'running') {
            return false;
        }
        if ($run->expires_at->isFuture()) {
            return true;
        }
        TaskVerification::query()->whereKey($run->id)->where('status', 'running')->update([
            'status' => 'interrupted', 'error' => 'Verification expired without a complete result.', 'finished_at' => now(),
        ]);

        return false;
    }

    /** @return list<TaskRubricItem> */
    public function items(Task $task): array
    {
        return $this->evaluate($task, $this->latest($task));
    }

    /** @return list<TaskRubricItem> */
    private function evaluate(Task $task, ?TaskVerification $run): array
    {
        if ($run?->status === 'error') {
            throw new TaskSessionClassificationException($run->error ?? 'Task verification is unavailable.');
        }
        $current = $run !== null && $this->matches($task, $run);
        $passed = $current && ($run->result['passed'] ?? false) === true;
        $items = [
            new TaskRubricItem('check_invoked', $current && ($run->result['checks'] ?? []) !== [], 'Run tasks-verify for this task and attempt.'),
            new TaskRubricItem('check_passed', $passed, 'The recorded checks did not pass. Inspect the verification result and fix the failed check.'),
            new TaskRubricItem('check_current', $passed && $this->current($task, $run), 'Verification is missing or stale. Run tasks-verify on the current files.'),
        ];
        foreach ($task->verification_criteria ?? [] as $criterion) {
            $probability = $run?->answers[$criterion['id']] ?? null;
            $accepted = $current && $run->status === 'complete' && is_numeric($probability)
                && is_finite((float) $probability) && $probability >= $run->threshold && $probability <= 1;
            $items[] = new TaskRubricItem('criterion:'.$criterion['id'], $accepted,
                'Criterion '.$criterion['id'].' is unverified. Supply an executed test that demonstrates: '.$criterion['requirement']);
        }
        if (($task->verification_criteria ?? []) === []) {
            $items[] = new TaskRubricItem('verification_plan', false, 'Verification criteria are missing. Ask for assistance; do not bypass the gate.');
        }

        return $items;
    }

    public function matches(Task $task, TaskVerification $run): bool
    {
        $instance = $task->taskGroup->taskable;

        try {
            $threshold = $this->policy->threshold();
        } catch (ResourceOperationException) {
            return false;
        }

        return $instance instanceof AppInstance && $run->task_id === $task->id
            && $run->attempt === $task->completion_attempt && $run->instance_id === $instance->id
            && $run->node_id === $instance->node_id
            && $run->checkout_path === $instance->checkout_path
            && $run->criteria_digest === $this->policy->digest($task->verification_criteria ?? [])
            && $run->policy_version === TaskVerificationPolicy::Version && $run->model === TaskVerificationPolicy::Model
            && $run->threshold === $threshold;
    }

    public function current(Task $task, TaskVerification $run): bool
    {
        $instance = $task->taskGroup->taskable;

        return $instance instanceof AppInstance && $this->matches($task, $run)
            && ($run->result['fingerprint'] ?? null) === $this->runner->fingerprint($instance);
    }

    public function accepted(Task $task): ?TaskVerification
    {
        $run = $this->latest($task);
        foreach ($this->evaluate($task, $run) as $item) {
            if (! $item->passed) {
                return null;
            }
        }

        return $run;
    }
}
