<?php

declare(strict_types=1);

namespace App\Infrastructure\Ai;

use App\Domain\Tasks\TaskSessionClassificationException;
use Closure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;

final class PendingClassification
{
    /** @var array<string, ChoiceAnswer|array<string, mixed>>|Closure(string|array<string, mixed>): array<string, ChoiceAnswer|array<string, mixed>>|null */
    private static array|Closure|null $fake = null;

    /** @var array<string, Choice> */
    private array $questions = [];

    /**
     * @param  string|array<string, mixed>  $state
     */
    public function __construct(private readonly string|array $state) {}

    /**
     * @param  array<string, ChoiceAnswer|array<string, mixed>>|Closure(string|array<string, mixed>): array<string, ChoiceAnswer|array<string, mixed>>|null  $responses
     */
    public static function fakeUsing(array|Closure|null $responses): void
    {
        self::$fake = $responses;
    }

    public static function isFaked(): bool
    {
        return self::$fake !== null;
    }

    public function question(string $name, Choice $question): self
    {
        $this->questions[$name] = $question;

        return $this;
    }

    /**
     * @return array<string, ChoiceAnswer>
     */
    public function classify(): array
    {
        if (self::$fake instanceof Closure) {
            return $this->normalize((self::$fake)($this->state));
        }

        if (is_array(self::$fake)) {
            return $this->normalize(self::$fake);
        }

        $key = $this->apiKey();

        if ($key === null) {
            throw new TaskSessionClassificationException(
                'TYPESAFE_API_KEY is missing. Task session routing will not invent a next action.',
            );
        }

        return $this->request($key);
    }

    /**
     * @param  array<string, ChoiceAnswer|array<string, mixed>>  $answers
     * @return array<string, ChoiceAnswer>
     */
    private function normalize(array $answers): array
    {
        $normalized = [];

        foreach ($answers as $name => $answer) {
            if ($answer instanceof ChoiceAnswer) {
                $normalized[$name] = $answer;

                continue;
            }

            $choice = $answer['choice'] ?? null;
            $confidence = $answer['confidence'] ?? 0.0;

            if (! is_string($choice) || $choice === '') {
                continue;
            }

            $probabilities = $answer['probabilities'] ?? [];
            $normalized[$name] = new ChoiceAnswer(
                $choice,
                is_numeric($confidence) ? (float) $confidence : 0.0,
                is_array($probabilities) ? $this->floats($probabilities) : [],
            );
        }

        return $normalized;
    }

    /**
     * @return array<string, ChoiceAnswer>
     */
    private function request(#[SensitiveParameter] string $key): array
    {
        $questions = [];

        foreach ($this->questions as $name => $question) {
            $questions[$name] = [
                'type' => 'choice',
                'instructions' => $question->instructions,
                'criteria' => $question->options,
            ];
        }

        try {
            $response = Http::connectTimeout(3.0)
                ->timeout(10.0)
                ->acceptJson()
                ->asJson()
                ->withToken($key)
                ->post($this->url(), [
                    'model' => config('ai.providers.typesafe.model', 'jev-latest'),
                    'state' => $this->state,
                    'questions' => $questions,
                ]);
        } catch (ConnectionException $exception) {
            throw new TaskSessionClassificationException('TypeSafe Jev classification failed.', previous: $exception);
        }

        if (! $response->successful()) {
            throw new TaskSessionClassificationException('TypeSafe Jev classification failed.');
        }

        $answers = $response->json('answers');

        if (! is_array($answers)) {
            throw new TaskSessionClassificationException('TypeSafe Jev classification failed.');
        }

        return $this->normalize($answers);
    }

    private function url(): string
    {
        $url = config('ai.providers.typesafe.url', 'https://api.typesafe.ai/v1/systemone');

        return is_string($url) && $url !== '' ? $url : 'https://api.typesafe.ai/v1/systemone';
    }

    private function apiKey(): ?string
    {
        $key = config('ai.providers.typesafe.key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * @param  array<mixed, mixed>  $values
     * @return array<string, float>
     */
    private function floats(array $values): array
    {
        $floats = [];

        foreach ($values as $key => $value) {
            if (is_string($key) && is_numeric($value)) {
                $floats[$key] = (float) $value;
            }
        }

        return $floats;
    }
}
