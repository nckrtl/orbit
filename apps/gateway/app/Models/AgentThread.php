<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tasks\AgentThreadState;
use App\Domain\Tasks\TaskBroadcastObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $task_group_id
 * @property int|null $task_id
 * @property int|null $node_id
 * @property string $driver
 * @property string $runtime_key
 * @property string $external_id
 * @property string $role
 * @property string|null $model
 * @property string|null $effort
 * @property AgentThreadState|null $state
 * @property int $observation_version
 * @property Carbon|null $observed_at
 * @property Carbon|null $archived_at
 * @property string|null $archive_command_id
 * @property int $archive_attempts
 * @property Carbon|null $archive_retry_at
 * @property string|null $observation_error
 * @property string|null $error
 * @property int|null $tokens
 * @property int|null $input_tokens
 * @property int|null $cached_input_tokens
 * @property int|null $output_tokens
 * @property int|null $model_calls
 * @property int|null $peak_context_tokens
 * @property int|null $lines_added
 * @property int|null $lines_deleted
 * @property int|null $t3_input_tokens
 * @property int|null $t3_cached_input_tokens
 * @property int|null $t3_output_tokens
 * @property int|null $t3_model_calls
 * @property int|null $t3_peak_context_tokens
 * @property int|null $t3_counted_total_processed_tokens
 * @property int|null $t3_observed_total_processed_tokens
 * @property int|null $t3_event_sequence
 * @property bool $t3_metrics_partial
 * @property bool $t3_metrics_initialized
 * @property Carbon|null $t3_metrics_collected_at
 * @property Carbon|null $t3_metrics_final_at
 * @property Carbon|null $t3_metrics_retry_at
 * @property int $t3_metrics_attempts
 * @property int $t3_metrics_activity_version
 * @property int|null $t3_metrics_observed_activity_version
 * @property-read Node|null $node
 */
#[ObservedBy([TaskBroadcastObserver::class])]
final class AgentThread extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = ['task_group_id', 'task_id', 'node_id', 'driver', 'runtime_key', 'external_id', 'role', 'model', 'effort', 'state', 'observation_version', 'observed_at', 'archived_at', 'archive_command_id', 'archive_attempts', 'archive_retry_at', 'observation_error', 'error', 'tokens', 'input_tokens', 'cached_input_tokens', 'output_tokens', 'model_calls', 'peak_context_tokens', 'lines_added', 'lines_deleted', 't3_input_tokens', 't3_cached_input_tokens', 't3_output_tokens', 't3_model_calls', 't3_peak_context_tokens', 't3_counted_total_processed_tokens', 't3_observed_total_processed_tokens', 't3_event_sequence', 't3_metrics_partial', 't3_metrics_initialized', 't3_metrics_collected_at', 't3_metrics_final_at', 't3_metrics_retry_at', 't3_metrics_attempts', 't3_metrics_activity_version', 't3_metrics_observed_activity_version'];

    /** @return BelongsTo<Node, $this> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    /** @return BelongsTo<TaskGroup, $this> */
    public function taskGroup(): BelongsTo
    {
        return $this->belongsTo(TaskGroup::class);
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** @return HasMany<AgentThreadSendLease, $this> */
    public function sendLeases(): HasMany
    {
        return $this->hasMany(AgentThreadSendLease::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['state' => AgentThreadState::class, 'observation_version' => 'integer', 'observed_at' => 'datetime', 'archived_at' => 'datetime', 'archive_retry_at' => 'datetime', 'archive_attempts' => 'integer', 'tokens' => 'integer', 'input_tokens' => 'integer', 'cached_input_tokens' => 'integer', 'output_tokens' => 'integer', 'model_calls' => 'integer', 'peak_context_tokens' => 'integer', 'lines_added' => 'integer', 'lines_deleted' => 'integer', 't3_input_tokens' => 'integer', 't3_cached_input_tokens' => 'integer', 't3_output_tokens' => 'integer', 't3_model_calls' => 'integer', 't3_peak_context_tokens' => 'integer', 't3_counted_total_processed_tokens' => 'integer', 't3_observed_total_processed_tokens' => 'integer', 't3_event_sequence' => 'integer', 't3_metrics_partial' => 'boolean', 't3_metrics_initialized' => 'boolean', 't3_metrics_collected_at' => 'datetime', 't3_metrics_final_at' => 'datetime', 't3_metrics_retry_at' => 'datetime', 't3_metrics_attempts' => 'integer', 't3_metrics_activity_version' => 'integer', 't3_metrics_observed_activity_version' => 'integer'];
    }
}
