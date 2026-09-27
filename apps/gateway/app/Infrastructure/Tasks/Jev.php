<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\Tasks\TaskSessionClassificationException;
use Laravel\Ai\Contracts\Question;
use Laravel\Ai\PendingResponses\PendingClassification;
use Laravel\Ai\Responses\ClassificationResponse;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Laravel\Ai\Responses\Data\ChoiceAnswer;
use Throwable;

final readonly class Jev
{
    public function __construct(private JevRecorder $recorder) {}

    /**
     * @param  array{task_group_id?: int|null, task_id?: int|null, task_ids?: array<int, int|string>|null, agent_thread_id?: string|null, approval_comment_id?: int|null, approval_changes?: list<string>|null}  $subject
     * @param  array<string, Question>  $questions
     * @param  string|array<string, mixed>  $state
     */
    public function classify(
        PendingClassification $classification,
        string $purpose,
        array $subject,
        array $questions,
        string|array $state,
    ): ClassificationResponse {
        $started = hrtime(true);
        $callStartedAt = now()->toIso8601String();

        try {
            $response = $classification->classify();
        } catch (Throwable $exception) {
            $this->recorder->record($purpose, $subject, $questions, $state, null, null, self::errorCode($exception), $started, $callStartedAt);

            $key = config('ai.providers.typesafe.key');
            $reason = ! is_string($key) || trim($key) === ''
                ? 'TypeSafe Jev is not configured. Set TYPESAFE_API_KEY.'
                : 'TypeSafe Jev request failed ('.class_basename($exception).').';

            throw new TaskSessionClassificationException($reason, previous: $exception);
        }

        $answers = [];
        foreach ($response->answers as $key => $answer) {
            $value = match (true) {
                $answer instanceof BooleanAnswer => $answer->isTrue(),
                $answer instanceof ChoiceAnswer => $answer->choice,
                default => $answer->toArray()['value'] ?? null,
            };
            $probabilities = match (true) {
                $answer instanceof BooleanAnswer => ['true' => $answer->probability, 'false' => 1 - $answer->probability],
                $answer instanceof ChoiceAnswer => $answer->probabilities,
                default => $answer->toArray()['probabilities'] ?? null,
            };
            $selectedProbability = match (true) {
                $answer instanceof BooleanAnswer => $answer->probability,
                $answer instanceof ChoiceAnswer => $answer->probabilities[$answer->choice] ?? null,
                default => null,
            };
            if (! is_float($selectedProbability) || ! is_finite($selectedProbability) || $selectedProbability < 0 || $selectedProbability > 1) {
                $selectedProbability = null;
            }
            if ($answer instanceof BooleanAnswer && $value === false && $selectedProbability !== null) {
                $selectedProbability = 1 - $selectedProbability;
            }
            $answers[$key] = [
                'value' => $value,
                'probabilities' => $probabilities,
                'provider_confidence' => $answer instanceof ChoiceAnswer ? $answer->confidence : ($answer->toArray()['confidence'] ?? null),
                'selected_answer_probability' => $selectedProbability,
            ];
        }
        $this->recorder->record($purpose, $subject, $questions, $state, $answers, $response->meta->model, null, $started, $callStartedAt);

        return $response;
    }

    private static function errorCode(Throwable $exception): string
    {
        $class = class_basename($exception);

        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $class));
    }
}
