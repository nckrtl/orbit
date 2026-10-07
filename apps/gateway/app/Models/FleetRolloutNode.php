<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Fleet\FleetNodeOutcome;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The result of one Node in a fleet rollout: the outcome, the failed step and its evidence, and what
 * the Node runs afterwards.
 *
 * @property int $id
 * @property int $fleet_rollout_id
 * @property int|null $node_id
 * @property string $node_name
 * @property int $position
 * @property FleetNodeOutcome $outcome
 * @property string|null $step
 * @property string|null $error_code
 * @property string|null $message
 * @property array<string, mixed>|null $evidence
 * @property string|null $cli_version
 * @property string|null $agent_version
 * @property string|null $footprint_digest
 * @property int $attempts
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property-read FleetRollout $rollout
 * @property-read Node|null $node
 */
final class FleetRolloutNode extends Model
{
    /** @var list<string> */
    #[\Override]
    protected $fillable = [
        'fleet_rollout_id',
        'node_id',
        'node_name',
        'position',
        'outcome',
        'step',
        'error_code',
        'message',
        'evidence',
        'cli_version',
        'agent_version',
        'footprint_digest',
        'attempts',
        'started_at',
        'finished_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'outcome' => FleetNodeOutcome::class,
            'evidence' => 'array',
            'position' => 'integer',
            'attempts' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<FleetRollout, $this> */
    public function rollout(): BelongsTo
    {
        return $this->belongsTo(FleetRollout::class, 'fleet_rollout_id');
    }

    /** @return BelongsTo<Node, $this> */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'node_id' => $this->node_id,
            'node' => $this->node_name,
            'position' => $this->position,
            'outcome' => $this->outcome->value,
            'step' => $this->step,
            'error_code' => $this->error_code,
            'message' => $this->message,
            'evidence' => $this->evidence,
            'cli_version' => $this->cli_version,
            'agent_version' => $this->agent_version,
            'footprint_digest' => $this->footprint_digest,
            'attempts' => $this->attempts,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
        ];
    }
}
