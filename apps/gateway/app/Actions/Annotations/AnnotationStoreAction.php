<?php

declare(strict_types=1);

namespace App\Actions\Annotations;

use App\Data\Annotations\AnnotationData;
use App\Data\Annotations\AnnotationInput;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\Broadcasting\RecordEventBroadcaster;
use App\Domain\Broadcasting\RecordEventType;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tasks\TaskExecutionMode;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskType;
use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use App\Models\Annotation;
use App\Models\AppInstance;
use App\Models\Task;
use App\Models\TaskGroup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class AnnotationStoreAction
{
    public function create(AppInstance $instance, AnnotationInput $input): Annotation
    {
        return DB::transaction(function () use ($instance, $input): Annotation {
            $instance = AppInstance::query()->lockForUpdate()->findOrFail($instance->id);
            if ($instance->status === AppInstanceState::Removing) {
                throw new ResourceOperationException('annotation.instance_removing', 'Cannot annotate an Instance that is being removed.', 409);
            }
            $context = app(CommandActivityInputSanitizer::class)->sanitizeProperties($input->context);
            $id = (string) $context['id'];
            $existing = Annotation::query()->find($id);
            if ($existing !== null) {
                if ($existing->app_instance_id !== $instance->id || $existing->context !== $context) {
                    throw new ResourceOperationException('annotation.conflict', 'This annotation was already submitted. Create a new annotation for a changed instruction.', 409);
                }

                return $existing;
            }
            $thread = $context['threadId'] ?? null;
            $group = TaskGroup::query()->create([
                'app_id' => $instance->app_id, 'taskable_type' => $instance->getMorphClass(), 'taskable_id' => $instance->id,
                'execution_mode' => TaskExecutionMode::ExistingThread, 'agent_driver' => 't3',
                'title' => mb_substr((string) $context['comment'], 0, 200), 'brief' => $context['comment'],
                'status' => TaskGroupStatus::Todo,
            ]);
            $task = Task::query()->create([
                'task_group_id' => $group->id, 'type' => TaskType::Annotation, 'position' => 1,
                'title' => $group->title, 'brief' => $group->brief, 'status' => TaskStatus::Todo,
                'target_thread_id' => $thread,
            ]);
            $annotation = Annotation::query()->create([
                'id' => $id, 'app_instance_id' => $instance->id, 'task_id' => $task->id,
                'context' => $context, 'delivery' => $thread ? 'queued' : 'error',
                'error' => $thread ? null : 'Select a T3 thread and retry delivery.',
                'submission_order' => 0, 'command_id' => (string) Str::uuid(), 'message_id' => (string) Str::uuid(),
            ]);

            return $this->record($annotation);
        });
    }

    public function transition(Annotation $annotation, string $status, ?string $summary): Annotation
    {
        return DB::transaction(function () use ($annotation, $status, $summary): Annotation {
            $annotation->refresh();
            $task = $annotation->task()->lockForUpdate()->firstOrFail();
            $next = $status === 'resolved' ? TaskStatus::Completed : TaskStatus::Running;
            if ($task->status === $next) {
                return $annotation;
            }
            if (in_array($task->status, [TaskStatus::Completed, TaskStatus::Cancelled, TaskStatus::Failed], true) || ($next === TaskStatus::Completed && $task->status !== TaskStatus::Running)) {
                throw new ResourceOperationException('annotation.invalid_transition', 'Mark the annotation in progress before resolving it.', 409);
            }
            $task->status = $next;
            $task->started_at ??= now();
            if ($next === TaskStatus::Completed) {
                $task->completion_summary = app(CommandActivityInputSanitizer::class)->sanitizeProperties(['summary' => $summary])['summary'];
                $task->settled_at = now();
                $task->duration_ms = max(0, (int) $task->started_at->diffInMilliseconds(now()));
            }
            $task->save();
            $task->taskGroup()->update([
                'status' => $next === TaskStatus::Completed ? TaskGroupStatus::Completed : TaskGroupStatus::Running,
                'started_at' => $task->started_at, 'settled_at' => $task->settled_at, 'duration_ms' => $task->duration_ms,
            ]);
            $annotation->setRelation('task', $task);
            $annotation->delivery = 'sent';
            $annotation->error = null;

            return $this->record($annotation);
        });
    }

    public function retry(Annotation $annotation, ?string $threadId = null): Annotation
    {
        return DB::transaction(function () use ($annotation, $threadId): Annotation {
            $annotation->refresh();
            $task = $annotation->task()->lockForUpdate()->firstOrFail();
            if ($threadId !== null && $threadId !== $task->target_thread_id) {
                if ($annotation->command !== null || $task->status !== TaskStatus::Todo) {
                    throw new ResourceOperationException('annotation.already_dispatched', 'A dispatched annotation cannot be assigned to another thread.', 409);
                }
                $task->target_thread_id = $threadId;
            }
            if ($annotation->delivery !== 'error' || $task->target_thread_id === null || $task->status !== TaskStatus::Todo) {
                throw new ResourceOperationException('annotation.not_retryable', 'This annotation cannot be retried.', 409);
            }
            $task->save();
            $annotation->setRelation('task', $task);
            $annotation->delivery = 'queued';
            $annotation->error = null;
            $annotation->lease_until = null;

            return $this->record($annotation);
        });
    }

    /** The caller holds the transaction so the record and its event commit together. */
    public function record(Annotation $annotation): Annotation
    {
        $sequence = DB::table('annotation_events')->insertGetId([
            'app_instance_id' => $annotation->app_instance_id, 'payload' => '{}',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $annotation->revision = $sequence;
        if ($annotation->submission_order === 0) {
            $annotation->submission_order = $sequence;
        }
        $annotation->save();
        DB::table('annotation_events')->where('id', $sequence)->update([
            'payload' => json_encode(AnnotationData::fromModel($annotation)->annotation, JSON_THROW_ON_ERROR),
        ]);

        $id = $annotation->id;
        $data = ['id' => $id, 'instanceId' => $annotation->app_instance_id, 'revision' => $sequence];
        DB::afterCommit(fn () => app(RecordEventBroadcaster::class)->broadcast(RecordEventType::AnnotationUpdated, $id, $data));

        return $annotation;
    }
}
