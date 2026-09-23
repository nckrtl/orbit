<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\Tasks\TaskJevDecision;
use App\Domain\Tasks\TaskJevOutcome;
use App\Domain\Tasks\TaskSessionClassificationException;
use App\Domain\Tasks\TaskSessionClassifier;
use App\Domain\Tasks\TaskSessionDecision;
use App\Domain\Tasks\TaskSessionNextAction;
use App\Domain\Tasks\TaskSessionObservation;
use App\Domain\Tasks\TaskThreadRole;
use App\Domain\Tasks\TaskTranscriptCheck;
use Laravel\Ai\Classification;
use Laravel\Ai\Classification\Choice;
use Laravel\Ai\Responses\Data\ChoiceAnswer;

final readonly class LaravelAiTaskSessionClassifier implements TaskSessionClassifier
{
    public function classifyOutcome(TaskSessionObservation $observation, TaskThreadRole $role): TaskJevDecision
    {
        $answers = Classification::of([
            ...$observation->toArray(),
            'classification_role' => $role->value,
            'completion_evidence' => $this->hasPassingComposerCheck($observation, $role),
        ])
            ->question('outcome', new Choice(
                'Which single Jev outcome applies to this stopped task thread?',
                TaskJevOutcome::choiceCriteria(),
            ))
            ->classify();

        $answer = $answers['outcome'] ?? null;
        if (! $answer instanceof ChoiceAnswer) {
            throw new TaskSessionClassificationException('TypeSafe Jev did not return an outcome Choice.');
        }

        $outcome = TaskJevOutcome::tryFrom($answer->choice) ?? TaskJevOutcome::AssistanceRequired;
        if ($role !== TaskThreadRole::Reviewer && $outcome === TaskJevOutcome::ChangesRequested) {
            $outcome = TaskJevOutcome::AssistanceRequired;
        }
        if ($outcome === TaskJevOutcome::CompletedSuccessfully && ! $this->hasPassingComposerCheck($observation, $role)) {
            $outcome = TaskJevOutcome::AssistanceRequired;
        }

        return new TaskJevDecision($outcome, $answer->confidence, 'Jev selected '.$outcome->value.'.');
    }

    public function classifyTranscript(TaskSessionObservation $observation, TaskThreadRole $role): array
    {
        $definitions = [
            'blocked' => $role === TaskThreadRole::Implementer
                ? ['Is the agent blocked on something the brief cannot resolve?', 'The agent is blocked.', 'The agent is not blocked.']
                : ['Is the reviewer blocked on something the review cannot resolve?', 'The reviewer is blocked.', 'The reviewer is not blocked.'],
        ];

        $evidence = $observation->transcriptEvidence($role);
        if ($evidence === null) {
            throw new TaskSessionClassificationException('The '.$role->value.' thread is not in the observation.');
        }

        $classification = Classification::of($evidence);
        foreach ($definitions as $key => [$prompt, $yes, $no]) {
            $classification = $classification->question($key, new Choice($prompt, ['yes' => $yes, 'no' => $no]));
        }

        $answers = $classification->classify();
        $checks = [];
        foreach (array_keys($definitions) as $key) {
            $answer = $answers[$key] ?? null;
            if (! $answer instanceof ChoiceAnswer || $answer->confidence === null) {
                throw new TaskSessionClassificationException('TypeSafe Jev did not return the '.$key.' check.');
            }
            $checks[$key] = new TaskTranscriptCheck($key, $answer->choice, $answer->confidence, $answer->probabilities);
        }

        return $checks;
    }

    public function classify(TaskSessionObservation $observation): TaskSessionDecision
    {
        $answers = Classification::of($observation->toArray())
            ->question('next_action', new Choice(
                'Which single next action should the Gateway task scheduler execute for these agent threads?',
                TaskSessionNextAction::choiceCriteria(),
            ))
            ->classify();

        $answer = $answers['next_action'] ?? null;

        if (! $answer instanceof ChoiceAnswer) {
            throw new TaskSessionClassificationException('TypeSafe Jev did not return a next_action Choice.');
        }

        $action = TaskSessionNextAction::tryFrom($answer->choice) ?? TaskSessionNextAction::EscalateCoder;
        $threshold = $this->threshold();

        if ($answer->confidence < $threshold) {
            return TaskSessionDecision::escalate(
                'Choice confidence '.$answer->confidence.' is below '.$threshold.'.',
                $answer->confidence,
            );
        }

        return new TaskSessionDecision(
            $action,
            $answer->confidence,
            'Jev selected '.$action->value.'.',
        );
    }

    private function threshold(): float
    {
        $threshold = config('orbit.tasks.jev_confidence_threshold', 0.75);

        return is_numeric($threshold) ? (float) $threshold : 0.75;
    }

    private function hasPassingComposerCheck(TaskSessionObservation $observation, TaskThreadRole $role): bool
    {
        $thread = $observation->thread($role);
        if ($thread === null) {
            return false;
        }

        foreach ($thread->recentMessages as $message) {
            $text = strtolower($message['text'].' '.$message['label']);
            if ($message['kind'] !== 'activity'
                || $message['label'] === 'assistant'
                || (! str_contains(strtolower($message['label']), 'tool') && ! str_contains($text, 'exit code'))
                || ! str_contains($text, 'composer check')
                || (str_contains($text, 'passed') || str_contains($text, 'exit code 0') || str_contains($text, 'code 0')) === false) {
                continue;
            }

            return true;
        }

        return false;
    }
}
