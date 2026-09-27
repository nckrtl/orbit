<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\Tasks\TaskSessionClassificationException;
use App\Infrastructure\Activity\CommandActivityInputSanitizer;
use App\Models\JevDecision;
use Laravel\Ai\PendingResponses\PendingClassification;
use Laravel\Ai\Responses\ClassificationResponse;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Laravel\Ai\Responses\Data\ChoiceAnswer;
use Throwable;

/**
 * Sends one TypeSafe Jev classification and durably records its request and outcome.
 */
final readonly class Jev
{
    /** @param array{task_group_id?: int|null, task_id?: int|null, task_ids?: array<int, int|string>|null, agent_thread_id?: string|null} $subject */
    public static function classify(PendingClassification $classification, string $purpose = 'unspecified', array $subject = []): ClassificationResponse
    {
        $started = hrtime(true);
        $state = self::property($classification, 'state');
        $questions = self::property($classification, 'questions');
        $questionData = [];
        foreach ($questions as $key => $question) {
            $questionData[$key] = is_object($question) && method_exists($question, 'toArray')
                ? $question->toArray()
                : ['type' => is_object($question) ? class_basename($question) : 'unknown'];
        }

        try {
            $response = $classification->classify();
        } catch (Throwable $exception) {
            self::record($purpose, $subject, $questionData, $state, null, null, self::errorCode($exception), $started);

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
        self::record($purpose, $subject, $questionData, $state, $answers, $response->meta->model, null, $started);

        return $response;
    }

    private static function property(PendingClassification $classification, string $name): mixed
    {
        $property = new \ReflectionProperty($classification, $name);

        return $property->getValue($classification);
    }

    /**
     * @param  array<string, mixed>  $subject
     * @param  array<string, array<string, mixed>>  $questions
     * @param  array<string, array<string, mixed>>|null  $answers
     */
    private static function record(string $purpose, array $subject, array $questions, mixed $state, ?array $answers, ?string $model, ?string $errorCode, int $started): void
    {
        JevDecision::query()->create([
            'purpose' => $purpose,
            'task_group_id' => $subject['task_group_id'] ?? null,
            'task_id' => $subject['task_id'] ?? null,
            'task_ids' => $subject['task_ids'] ?? null,
            'agent_thread_id' => $subject['agent_thread_id'] ?? null,
            'questions' => self::redact($questions),
            'input_state' => self::redact($state),
            'answers' => $answers,
            'provider_model' => $model,
            'latency_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
            'error_code' => $errorCode,
        ]);
    }

    private static function redact(mixed $value, ?string $key = null): mixed
    {
        return (new CommandActivityInputSanitizer)->sanitize($value, $key);
    }

    private static function errorCode(Throwable $exception): string
    {
        $class = class_basename($exception);

        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $class));
    }
}
