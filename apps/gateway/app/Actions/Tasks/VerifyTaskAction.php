<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Data\Tasks\VerifyTaskData;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskCheckResult;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskEvidenceJudge;
use App\Domain\Tasks\TaskSessionClassificationException;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskVerificationGate;
use App\Domain\Tasks\TaskVerificationPolicy;
use App\Models\AppInstance;
use App\Models\Task;
use App\Models\TaskVerification;
use Illuminate\Support\Facades\DB;

final readonly class VerifyTaskAction
{
    public function __construct(private RequireTasksExtensionAction $extension, private TaskCheckRunner $runner,
        private TaskEvidenceJudge $judge, private TaskVerificationGate $gate, private TaskVerificationPolicy $policy) {}

    public function execute(Task $task, VerifyTaskData $data): TaskVerification
    {
        $this->extension->execute();
        $execute = false;
        $reuse = false;
        $run = DB::transaction(function () use ($task, $data, &$execute, &$reuse): TaskVerification {
            $task = Task::query()->lockForUpdate()->findOrFail($task->id);
            $instance = $task->taskGroup->taskable;
            if (! $task->verification_required || $task->status !== TaskStatus::Running || ! $instance instanceof AppInstance
                || $task->assistance_requested || $task->taskGroup->assistance_requested) {
                throw new ResourceOperationException('tasks.verification_unavailable', 'Verification requires an active pilot task and its assigned workspace.', 409);
            }
            $ids = array_column($task->verification_criteria ?? [], 'id');
            $references = array_column($data->references, 'criterion_id');
            sort($ids);
            sort($references);
            if ($ids === [] || $ids !== $references) {
                throw new ResourceOperationException('tasks.verification_evidence_required', 'Supply exactly one test reference for every verification criterion.', 422);
            }
            $existing = TaskVerification::query()->where('task_id', $task->id)->where('run_key', $data->runKey)->first();
            if ($existing !== null) {
                if ($existing->references !== $data->references || $existing->attempt !== $task->completion_attempt) {
                    throw new ResourceOperationException('tasks.verification_key_conflict', 'This run key belongs to another attempt or evidence request.', 409);
                }

                if ($existing->status === 'error' && ($existing->result['passed'] ?? false) === true
                    && $existing->answers === null && $existing->semantic_attempts < 3
                    && $this->gate->latest($task)?->id === $existing->id) {
                    $execute = true;
                    $reuse = true;
                    $existing->update(['status' => 'running', 'error' => null, 'expires_at' => now()->addSeconds(900)]);
                }

                return $existing;
            }
            if ($this->gate->busy($task)) {
                throw new ResourceOperationException('tasks.verification_running', 'Verification is already running for this task.', 409);
            }
            $execute = true;

            return TaskVerification::query()->create([
                'task_id' => $task->id, 'attempt' => $task->completion_attempt, 'run_key' => $data->runKey,
                'instance_id' => $instance->id, 'node_id' => $instance->node_id, 'checkout_path' => $instance->checkout_path,
                'criteria_digest' => $this->policy->digest($task->verification_criteria ?? []),
                'policy_version' => TaskVerificationPolicy::Version, 'status' => 'running', 'references' => $data->references,
                'model' => TaskVerificationPolicy::Model, 'threshold' => $this->policy->threshold(), 'expires_at' => now()->addSeconds(900),
            ]);
        });
        if (! $execute) {
            return $run;
        }
        $task->refresh();
        $instance = $task->taskGroup->taskable;
        if (! $instance instanceof AppInstance) {
            throw new ResourceOperationException('tasks.verification_unavailable', 'The assigned workspace is unavailable.', 409);
        }
        try {
            if ($reuse && ! $this->gate->current($task, $run)) {
                $run->update(['status' => 'interrupted', 'error' => 'The stored checks are stale. Start a new verification run.', 'finished_at' => now()]);

                return $run;
            }
            $result = $reuse ? TaskCheckResult::fromStored($run->result ?? []) : $this->runner->run($instance, $data->references);
            $run->update(['result' => $result->toArray()]);
            $missing = array_diff(array_column($task->verification_criteria ?? [], 'id'), array_keys($result->evidence));
            if ($missing !== []) {
                $run->update(['error' => 'Executed test evidence is missing for: '.implode(', ', $missing).'. Check the exact test name and file.']);
            }
            if ($result->passed && $missing === []) {
                $key = $this->policy->digest([$result->fingerprint, $result->evidence, $run->criteria_digest, $run->policy_version, $run->model, $run->threshold]);
                $cached = TaskVerification::query()->where('task_id', $task->id)->where('attempt', $run->attempt)
                    ->where('evaluation_key', $key)->whereNotNull('answers')->latest('id')->first();
                $run->update(['evaluation_key' => $key]);
                if ($cached !== null) {
                    $answers = $cached->answers;
                } else {
                    $run->increment('semantic_attempts');
                    $judgment = $this->judge->judge($task->verification_criteria ?? [], $result->evidence);
                    $answers = $judgment->probabilities;
                    $run->update(['semantic_input_tokens' => $judgment->inputTokens, 'semantic_duration_ms' => $judgment->durationMs]);
                }
                $run->update(['answers' => $answers]);
            }
            $currentTask = $task->fresh();
            $valid = $currentTask instanceof Task && $currentTask->status === TaskStatus::Running
                && $this->gate->matches($currentTask, $run) && $this->gate->latest($currentTask)?->id === $run->id;
            TaskVerification::query()->whereKey($run->id)->where('status', 'running')->update([
                'status' => $valid ? 'complete' : 'interrupted', 'finished_at' => now(),
            ]);
        } catch (TaskSessionClassificationException $exception) {
            TaskVerification::query()->whereKey($run->id)->where('status', 'running')->update([
                'status' => 'error', 'error' => $exception->getMessage(), 'finished_at' => now(),
            ]);
        }

        return $run->refresh();
    }
}
