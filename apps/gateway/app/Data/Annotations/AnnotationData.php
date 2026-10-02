<?php

declare(strict_types=1);

namespace App\Data\Annotations;

use App\Domain\Tasks\TaskStatus;
use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use App\Models\Annotation;
use Spatie\LaravelData\Data;

final class AnnotationData extends Data
{
    /** @param array<string, mixed> $annotation */
    public function __construct(public array $annotation) {}

    public static function fromModel(Annotation $annotation): self
    {
        return new self(app(CommandActivityInputSanitizer::class)->sanitizeProperties([
            ...$annotation->context,
            'id' => $annotation->id,
            'instanceId' => $annotation->app_instance_id,
            'taskId' => $annotation->task_id,
            'taskGroupId' => $annotation->task->task_group_id,
            'threadId' => $annotation->task->target_thread_id,
            'status' => match ($annotation->task->status) {
                TaskStatus::Completed => 'resolved', TaskStatus::Cancelled => 'cancelled', TaskStatus::Running, TaskStatus::Reviewing => 'in_progress', default => 'pending',
            },
            'delivery' => $annotation->delivery,
            'revision' => $annotation->revision,
            'syncError' => $annotation->error,
            'summary' => $annotation->task->completion_summary,
        ]));
    }
}
