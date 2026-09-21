<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Domain\Tasks\T3ThreadReader;
use App\Domain\Tasks\TaskThreadState;
use App\Models\TaskAgentSession;
use App\Models\TaskGroup;
use Illuminate\Database\Eloquent\Collection;

final readonly class ShowTaskAgentSessionsAction
{
    public function __construct(
        private RequireTasksExtensionAction $requireExtension,
        private T3ThreadReader $threads,
    ) {}

    /** @return Collection<int, TaskAgentSession> */
    public function execute(TaskGroup $group): Collection
    {
        $this->requireExtension->execute();

        $sessions = TaskAgentSession::query()->with('node')->where('task_group_id', $group->id)->orderBy('id')->get();

        foreach ($sessions as $session) {
            if ($session->node === null) {
                continue;
            }

            $snapshot = $this->threads->snapshot($session->node, $session->thread_id);
            $status = is_array($snapshot) ? data_get($snapshot, 'thread.session.status') : null;
            $session->setAttribute('state', TaskThreadState::fromSessionStatus($status));
        }

        return $sessions;
    }
}
