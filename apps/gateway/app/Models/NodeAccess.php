<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\TaskVms\TaskVmException;
use App\Domain\TaskVms\TaskVmPlacement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $consumer_node_id
 * @property int $serving_node_id
 * @property-read Node $consumer
 * @property-read Node $serving
 */
final class NodeAccess extends Model
{
    /** @var string|null */
    #[\Override]
    protected $table = 'node_access';

    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'consumer_node_id',
        'serving_node_id',
    ];

    /** A task VM Node holds no access edge to another Node. */
    protected static function booted(): void
    {
        self::saving(static function (self $access): void {
            $consumer = Node::query()->find($access->consumer_node_id);
            if ($consumer instanceof Node && TaskVmPlacement::forNode($consumer) !== null) {
                throw new TaskVmException('task_vm.access_refused', "Node [{$consumer->id}] is a task VM and cannot access other Nodes.");
            }
        });
    }

    /** @return BelongsTo<Node, $this> */
    public function consumer(): BelongsTo
    {
        return $this->belongsTo(Node::class, 'consumer_node_id');
    }

    /** @return BelongsTo<Node, $this> */
    public function serving(): BelongsTo
    {
        return $this->belongsTo(Node::class, 'serving_node_id');
    }
}
