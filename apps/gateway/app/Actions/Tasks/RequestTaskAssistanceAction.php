<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Tasks\CoderSettleNotifier;
use App\Models\Activity;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TaskGroup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class RequestTaskAssistanceAction
{
    public function __construct(private CoderSettleNotifier $notifier) {}

    public function execute(Task|TaskGroup $subject, string $reason, ?TaskComment $comment = null): bool
    {
        return DB::transaction(function () use ($subject, $reason, $comment): bool {
            $task = $subject instanceof Task ? Task::query()->lockForUpdate()->findOrFail($subject->id) : null;
            $group = TaskGroup::query()->lockForUpdate()->findOrFail($task->task_group_id ?? $subject->id);

            if ($task?->assistance_requested || $group->assistance_requested) {
                return false;
            }

            $group->update(['assistance_requested' => true, 'assistance_reason' => $reason]);
            $task?->update(['assistance_requested' => true, 'assistance_reason' => $reason]);
            Activity::query()->create([
                'log_name' => 'tasks', 'description' => 'assistance requested',
                'subject_type' => $subject::class, 'subject_id' => $subject->id,
                'properties' => ['comment_id' => $comment?->id, 'actor' => $comment->author ?? 'scheduler', 'reason' => $reason],
                'request_id' => (string) Str::uuid(), 'command' => $comment === null ? 'tasks:tick' : 'tasks:comment',
                'status' => 'completed',
            ]);
            DB::afterCommit(fn () => $this->notifier->assistance($group, $reason));

            return true;
        });
    }
}
