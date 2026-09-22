<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\AgentThread;
use App\Models\TaskGroup;

final readonly class TaskSessionActor
{
    public function __construct(private AgentDriverRegistry $drivers, private CoderSettleNotifier $coder) {}

    public function execute(TaskGroup $group, TaskSessionObservation $observation, TaskSessionDecision $decision): void
    {
        if ($decision->action === TaskSessionNextAction::EscalateCoder) {
            $this->coder->escalate($group, $observation, $decision);
        }
    }

    public function relayReviewBody(TaskGroup $group, TaskThreadObservation $observed, string $body): void
    {
        $thread = $this->thread($group, $observed);
        $this->drivers->get($thread->driver)->send($thread, "Relay from the reviewer. Address these findings verbatim, then run composer check.\n\n".$body);
    }

    public function remindRubric(TaskGroup $group, TaskThreadObservation $observed, string $message): void
    {
        $thread = $this->thread($group, $observed);
        $this->drivers->get($thread->driver)->send($thread, $message);
    }

    private function thread(TaskGroup $group, TaskThreadObservation $observed): AgentThread
    {
        if (! $observed->available) {
            throw new AgentDriverException('Agent observation unavailable.');
        }
        $thread = AgentThread::query()->where('task_group_id', $group->id)->where('id', $observed->threadId)->first();

        return $thread ?? throw new AgentDriverException('Agent conversation is unavailable.');
    }
}
