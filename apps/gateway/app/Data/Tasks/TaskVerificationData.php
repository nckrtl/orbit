<?php

declare(strict_types=1);

namespace App\Data\Tasks;

use App\Models\TaskVerification;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class TaskVerificationData extends Data
{
    /**
     * @param  array<string, float>|null  $answers
     * @param  list<array<string, mixed>>  $checks
     * @param  list<array{criterion_id: string, project: string, path: string, test: string}>  $evidence
     */
    public function __construct(
        public int $id,
        public string $runKey,
        public int $attempt,
        public string $status,
        public array $checks,
        public ?array $answers,
        public float $threshold,
        public ?string $error,
        public ?int $semanticInputTokens,
        public ?int $semanticDurationMs,
        public ?string $fingerprint,
        public string $criteriaDigest,
        public string $model,
        public array $evidence,
        public ?bool $checksPassed,
        public ?float $seconds,
    ) {}

    public static function fromModel(TaskVerification $run): self
    {
        $checks = $run->result['checks'] ?? [];

        return new self($run->id, $run->run_key, $run->attempt, $run->status,
            is_array($checks) ? $checks : [], $run->answers, $run->threshold, $run->error, $run->semantic_input_tokens, $run->semantic_duration_ms,
            $run->result['fingerprint'] ?? null, $run->criteria_digest, $run->model, $run->references,
            $run->result['passed'] ?? null, isset($run->result['seconds']) ? (float) $run->result['seconds'] : null);
    }
}
