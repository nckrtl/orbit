<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\TaskVms\TaskVmState;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One disposable VM that serves one task group as a normal `app-dev` Node.
 *
 * @property int $id
 * @property int $group_id
 * @property int $host_node_id
 * @property int|null $node_id
 * @property string $provider
 * @property string $name
 * @property TaskVmState $state
 * @property string|null $address
 * @property string $wireguard_ip
 * @property string $pi_token
 * @property string|null $model_key
 * @property string|null $error_code
 * @property string|null $error_message
 * @property Carbon|null $ready_at
 * @property Carbon|null $destroyed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Task $group
 * @property-read Node $hostNode
 * @property-read Node|null $node
 */
final class TaskVm extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'group_id', 'host_node_id', 'node_id', 'provider', 'name', 'state', 'address', 'wireguard_ip',
        'pi_token', 'model_key', 'error_code', 'error_message', 'ready_at', 'destroyed_at',
    ];

    /** @var list<string> */
    #[\Override]
    protected $hidden = ['pi_token', 'model_key'];

    /** @return array<string, string> */
    #[\Override]
    protected function casts(): array
    {
        return [
            'state' => TaskVmState::class,
            'pi_token' => 'encrypted',
            'model_key' => 'encrypted',
            'ready_at' => 'datetime',
            'destroyed_at' => 'datetime',
        ];
    }

    /** @param Builder<self> $query */
    #[Scope]
    protected function live(Builder $query): void
    {
        $query->where('state', '!=', TaskVmState::Destroyed);
    }

    /** @return BelongsTo<Task, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'group_id')->withoutGlobalScope('subtask');
    }

    /** @return BelongsTo<Node, $this> */
    public function hostNode(): BelongsTo
    {
        return $this->belongsTo(Node::class, 'host_node_id');
    }

    /** @return BelongsTo<Node, $this> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class, 'node_id');
    }
}
