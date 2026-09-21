<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $task_group_id
 * @property int|null $task_id
 * @property int|null $node_id
 * @property string $role
 * @property string $thread_id
 * @property-read Node|null $node
 */
final class TaskAgentSession extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = ['task_group_id', 'task_id', 'node_id', 'role', 'thread_id'];

    /** @return BelongsTo<Node, $this> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }
}
