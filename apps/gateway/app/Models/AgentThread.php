<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Tasks\AgentThreadState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 * @property-read Node|null $node
 */
final class AgentThread extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = ['task_group_id', 'task_id', 'node_id', 'driver', 'runtime_key', 'external_id', 'role', 'model', 'effort', 'state', 'observation_version', 'observed_at', 'observation_error', 'error', 'tokens', 'input_tokens', 'cached_input_tokens', 'output_tokens', 'model_calls', 'peak_context_tokens', 'lines_added', 'lines_deleted'];

    /** @return BelongsTo<Node, $this> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['state' => AgentThreadState::class, 'observation_version' => 'integer', 'observed_at' => 'datetime', 'tokens' => 'integer', 'input_tokens' => 'integer', 'cached_input_tokens' => 'integer', 'output_tokens' => 'integer', 'model_calls' => 'integer', 'peak_context_tokens' => 'integer', 'lines_added' => 'integer', 'lines_deleted' => 'integer'];
    }
}
