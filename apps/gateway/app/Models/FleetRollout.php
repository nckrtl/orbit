<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Fleet\FleetRolloutStatus;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One rollout of a desired fleet state to the managed Nodes (ADR 0202). It belongs to the verified
 * Gateway release whose commit it rolls out, when that release has a record.
 *
 * @property int $id
 * @property int|null $gateway_release_id
 * @property string $commit
 * @property FleetRolloutStatus $status
 * @property array<string, mixed> $desired_state
 * @property list<int> $order
 * @property int|null $halted_node_id
 * @property string|null $error_code
 * @property string|null $message
 * @property array<string, mixed>|null $alert
 * @property array<string, mixed>|null $notices
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read GatewayRelease|null $release
 * @property-read Node|null $haltedNode
 * @property-read Collection<int, FleetRolloutNode> $nodes
 */
final class FleetRollout extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'gateway_release_id',
        'commit',
        'status',
        'desired_state',
        'order',
        'halted_node_id',
        'error_code',
        'message',
        'alert',
        'notices',
        'started_at',
        'finished_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => FleetRolloutStatus::class,
            'desired_state' => 'array',
            'order' => 'array',
            'alert' => 'array',
            'notices' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<GatewayRelease, $this> */
    public function release(): BelongsTo
    {
        return $this->belongsTo(GatewayRelease::class, 'gateway_release_id');
    }

    /** @return BelongsTo<Node, $this> */
    public function haltedNode(): BelongsTo
    {
        return $this->belongsTo(Node::class, 'halted_node_id');
    }

    /** @return HasMany<FleetRolloutNode, $this> */
    public function nodes(): HasMany
    {
        return $this->hasMany(FleetRolloutNode::class)->orderBy('position')->orderBy('id');
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'id' => $this->id,
            'release' => $this->release?->release_id,
            'gateway_release_id' => $this->gateway_release_id,
            'commit' => $this->commit,
            'status' => $this->status->value,
            'halted_node' => $this->haltedNode?->name,
            'error_code' => $this->error_code,
            'message' => $this->message,
            'alert' => $this->alert,
            'desired_state' => $this->desired_state,
            'nodes' => $this->nodes->map(static fn (FleetRolloutNode $node): array => $node->payload())->values()->all(),
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
