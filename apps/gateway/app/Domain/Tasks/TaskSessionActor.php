<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\AppInstance;
use App\Models\Node;
use App\Models\TaskGroup;
use Illuminate\Support\Str;

final readonly class TaskSessionActor
{
    public function __construct(
        private T3Dispatcher $dispatcher,
        private CoderSettleNotifier $coder,
    ) {}

    public function execute(TaskGroup $group, TaskSessionObservation $observation, TaskSessionDecision $decision): void
    {
        $group->loadMissing(['app', 'tasks', 'taskable']);

        match ($decision->action) {
            TaskSessionNextAction::DrainApproval => $this->drainApproval($group, $observation),
            TaskSessionNextAction::DrainUserInput => $this->drainUserInput($group, $observation),
            TaskSessionNextAction::ContinueImplementer => $this->continueImplementer($group, $observation),
            TaskSessionNextAction::RelayReviewToImplementer => $this->relayReview($group, $observation),
            TaskSessionNextAction::EscalateCoder => $this->coder->escalate($group, $observation, $decision),
            TaskSessionNextAction::MarkSubtaskDone,
            TaskSessionNextAction::SettleGroup,
            TaskSessionNextAction::Noop => null,
        };
    }

    private function drainApproval(TaskGroup $group, TaskSessionObservation $observation): void
    {
        foreach ($observation->threads as $thread) {
            if ($thread->pendingApprovalId === null) {
                continue;
            }

            $this->dispatch($group, [
                'type' => 'thread.approval.respond',
                'commandId' => (string) Str::uuid(),
                'threadId' => $thread->threadId,
                'requestId' => $thread->pendingApprovalId,
                'decision' => 'acceptForSession',
                'createdAt' => now()->toIso8601String(),
            ]);
        }
    }

    private function drainUserInput(TaskGroup $group, TaskSessionObservation $observation): void
    {
        foreach ($observation->threads as $thread) {
            if ($thread->pendingUserInputId === null) {
                continue;
            }

            $this->dispatch($group, [
                'type' => 'thread.user-input.respond',
                'commandId' => (string) Str::uuid(),
                'threadId' => $thread->threadId,
                'requestId' => $thread->pendingUserInputId,
                'answers' => [
                    'continue' => true,
                    'expand_scope' => false,
                    'note' => 'Continue the current brief. Do not expand scope.',
                ],
                'createdAt' => now()->toIso8601String(),
            ]);
        }
    }

    private function continueImplementer(TaskGroup $group, TaskSessionObservation $observation): void
    {
        $implementer = $observation->thread(TaskThreadRole::Implementer);

        if (! $implementer instanceof TaskThreadObservation) {
            return;
        }

        $this->startTurn(
            $group,
            $implementer->threadId,
            'Continue the current brief. Do not expand scope.',
            TaskAgentDefaults::implementerSelection($group->implementer_model),
        );
    }

    private function relayReview(TaskGroup $group, TaskSessionObservation $observation): void
    {
        $implementer = $observation->thread(TaskThreadRole::Implementer);
        $reviewer = $observation->thread(TaskThreadRole::Reviewer);

        if (! $implementer instanceof TaskThreadObservation) {
            return;
        }

        $excerpt = 'The reviewer asked you to continue.';

        if ($reviewer instanceof TaskThreadObservation) {
            $excerpt = $reviewer->lastAssistantText ?? $reviewer->lastUserText ?? $excerpt;
        }

        $this->startTurn(
            $group,
            $implementer->threadId,
            implode("\n\n", [
                'Relay from the reviewer. Continue the current brief. Do not expand scope.',
                $excerpt,
            ]),
            TaskAgentDefaults::implementerSelection($group->implementer_model),
        );
    }

    /**
     * @param  array{instanceId: string, model: string, options: list<array{id: string, value: string}>}  $selection
     */
    private function startTurn(TaskGroup $group, string $threadId, string $text, array $selection): void
    {
        $messageId = (string) Str::uuid();

        $this->dispatch($group, [
            'type' => 'thread.turn.start',
            'commandId' => (string) Str::uuid(),
            'threadId' => $threadId,
            'message' => [
                'messageId' => $messageId,
                'role' => 'user',
                'text' => $text,
                'attachments' => [],
            ],
            'modelSelection' => $selection,
            'runtimeMode' => 'full-access',
            'interactionMode' => 'default',
            'createdAt' => now()->toIso8601String(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $command
     */
    private function dispatch(TaskGroup $group, array $command): void
    {
        $node = $this->node($group);

        if (! $node instanceof Node) {
            throw new T3DispatchException('Task workspace Node is missing.');
        }

        $this->dispatcher->dispatch($node, $command);
    }

    private function node(TaskGroup $group): ?Node
    {
        $instance = $group->taskable;

        if (! $instance instanceof AppInstance) {
            return null;
        }

        $instance->loadMissing('node');

        return $instance->node;
    }
}
