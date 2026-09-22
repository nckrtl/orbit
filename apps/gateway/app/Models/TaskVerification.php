<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $task_id
 * @property int $attempt
 * @property int $instance_id
 * @property int $node_id
 * @property string $run_key
 * @property string $checkout_path
 * @property string $criteria_digest
 * @property string $policy_version
 * @property string $status
 * @property list<array{criterion_id: string, project: string, path: string, test: string}> $references
 * @property array<string, mixed>|null $result
 * @property array<string, float>|null $answers
 * @property string|null $evaluation_key
 * @property string $model
 * @property float $threshold
 * @property int|null $semantic_input_tokens
 * @property int|null $semantic_duration_ms
 * @property int $semantic_attempts
 * @property string|null $error
 * @property Carbon $expires_at
 * @property Carbon|null $finished_at
 */
final class TaskVerification extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = ['task_id', 'attempt', 'run_key', 'instance_id', 'node_id', 'checkout_path', 'criteria_digest',
        'policy_version', 'status', 'references', 'result', 'answers', 'evaluation_key', 'model', 'threshold',
        'semantic_input_tokens', 'semantic_duration_ms', 'semantic_attempts', 'error', 'expires_at', 'finished_at'];

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['attempt' => 'integer', 'instance_id' => 'integer', 'node_id' => 'integer', 'references' => 'array', 'result' => 'array',
            'answers' => 'array', 'threshold' => 'float', 'semantic_attempts' => 'integer',
            'expires_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime'];
    }
}
