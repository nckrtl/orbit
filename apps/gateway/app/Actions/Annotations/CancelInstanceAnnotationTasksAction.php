<?php

declare(strict_types=1);

namespace App\Actions\Annotations;

use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskType;
use App\Models\Annotation;
use App\Models\Instance;
use App\Models\Task;
use Illuminate\Support\Facades\DB;

final readonly class CancelInstanceAnnotationTasksAction
{
    public function __construct(private AnnotationStoreAction $store) {}

    public function execute(int $instanceId): void
    {
        DB::transaction(function () use ($instanceId): void {
            $tasks = Task::query()->where('type', TaskType::Annotation)
                ->whereNotIn('status', [TaskStatus::Completed, TaskStatus::Cancelled])
                ->whereHas('parent', static fn ($q) => $q->whereIn('taskable_type', Instance::morphTypes())->where('taskable_id', $instanceId))
                ->lockForUpdate()->get();
            foreach ($tasks as $task) {
                $task->update(['status' => TaskStatus::Cancelled, 'settled_at' => now(), 'completion_summary' => 'Cancelled because the Instance is being removed.']);
                $group = $task->parent;
                if (! $group->tasks()->whereNotIn('status', [TaskStatus::Completed, TaskStatus::Cancelled])->exists()) {
                    $group->update(['status' => TaskGroupStatus::Cancelled, 'settled_at' => now()]);
                }
                $annotation = Annotation::query()->where('task_id', $task->id)->first();
                if ($annotation !== null) {
                    $annotation->setRelation('task', $task);
                    $annotation->delivery = 'cancelled';
                    $annotation->error = null;
                    $annotation->lease_until = null;
                    $this->store->record($annotation);
                }
            }
        });
    }
}
