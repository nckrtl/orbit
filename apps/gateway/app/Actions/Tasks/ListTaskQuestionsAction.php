<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Tasks\QuestionCause;
use App\Domain\Tasks\QuestionStatus;
use App\Models\TaskQuestion;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

final readonly class ListTaskQuestionsAction
{
    public function __construct(private RequireTasksExtensionAction $requireExtension) {}

    /**
     * @return Collection<int, TaskQuestion>
     */
    public function execute(?int $projectId, ?QuestionCause $cause, ?QuestionStatus $status, ?Carbon $since): Collection
    {
        $this->requireExtension->execute();

        return TaskQuestion::query()
            ->when($projectId !== null, static fn ($query) => $query->whereHas(
                'task',
                static fn ($task) => $task->withoutGlobalScope('subtask')->where('project_id', $projectId),
            ))
            ->when($cause instanceof QuestionCause, static fn ($query) => $query->where('cause', $cause))
            ->when($status instanceof QuestionStatus, static fn ($query) => $query->where('status', $status))
            ->when($since instanceof Carbon, static fn ($query) => $query->where('asked_at', '>=', $since))
            ->orderByDesc('asked_at')
            ->orderByDesc('id')
            ->get();
    }
}
