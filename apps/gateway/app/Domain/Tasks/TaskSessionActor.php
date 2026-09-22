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

            return;
        }
        if (in_array($decision->action, [TaskSessionNextAction::Noop, TaskSessionNextAction::MarkSubtaskDone, TaskSessionNextAction::SettleGroup], true)) {
            return;
        }
        if (in_array($decision->action, [TaskSessionNextAction::DrainApproval, TaskSessionNextAction::DrainUserInput], true)) {
            $responded = false;
            foreach ($observation->threads as $observed) {
                $kind = $decision->action === TaskSessionNextAction::DrainApproval ? 'approval' : 'question';
                foreach ($observed->inputRequests as $request) {
                    if ($request->kind !== $kind) {
                        continue;
                    }
                    $thread = $this->thread($group, $observed);
                    $answers = $kind === 'approval' ? ['approve' => true] : [
                        'continue' => true, 'expand_scope' => false, 'note' => 'Continue the current brief. Do not expand scope.',
                    ];
                    $this->drivers->get($thread->driver)->respond($thread, $request, $answers);
                    $responded = true;
                }
            }
            if (! $responded) {
                throw new AgentDriverException('No matching agent input request is available.');
            }

            return;
        }
        $implementer = $observation->thread(TaskThreadRole::Implementer);
        if ($implementer === null) {
            throw new AgentDriverException('Implementer observation is unavailable.');
        }
        $thread = $this->thread($group, $implementer);
        if ($implementer->sessState === AgentThreadState::Working->value || $implementer->sessState === AgentThreadState::AskingForInput->value) {
            throw new AgentDriverException('The implementer cannot start a follow-up turn in this state.');
        }
        $message = 'Continue the current brief. Do not expand scope.';
        if ($decision->action === TaskSessionNextAction::RelayReviewToImplementer) {
            $reviewer = $observation->thread(TaskThreadRole::Reviewer);
            $message = "Relay from the reviewer. Continue the current brief. Do not expand scope.\n\n".($reviewer->lastAssistantText ?? $reviewer->lastUserText ?? 'The reviewer asked you to continue.');
        }
        $this->drivers->get($thread->driver)->send($thread, $message);
    }

    public function remindCompletion(TaskGroup $group, TaskThreadObservation $observed): void
    {
        $thread = $this->thread($group, $observed);
        $this->drivers->get($thread->driver)->send(
            $thread,
            'Before requesting review, post a ready_for_review comment with the full validation evidence from a passing composer check.',
        );
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
