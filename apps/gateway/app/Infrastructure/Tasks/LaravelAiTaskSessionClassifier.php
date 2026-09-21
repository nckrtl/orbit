<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\Tasks\TaskSessionClassificationException;
use App\Domain\Tasks\TaskSessionClassifier;
use App\Domain\Tasks\TaskSessionDecision;
use App\Domain\Tasks\TaskSessionNextAction;
use App\Domain\Tasks\TaskSessionObservation;
use Laravel\Ai\Classification;
use Laravel\Ai\Classification\Choice;
use Laravel\Ai\Responses\Data\ChoiceAnswer;

final readonly class LaravelAiTaskSessionClassifier implements TaskSessionClassifier
{
    public function classify(TaskSessionObservation $observation): TaskSessionDecision
    {
        $answers = Classification::of($observation->toArray())
            ->question('next_action', new Choice(
                'Which single next action should the Gateway task scheduler execute for these T3 task threads?',
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
}
