<?php

declare(strict_types=1);

namespace App\Data\Fleet;

use App\Models\FleetRollout;
use App\Models\FleetRolloutNode;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/** One fleet rollout: the desired state it rolls out and each Node's result. */
#[MapOutputName(SnakeCaseMapper::class)]
final class FleetRolloutData extends Data
{
    /**
     * @param  array<string, mixed>  $desiredState
     * @param  array<string, mixed>|null  $alert
     * @param  list<FleetRolloutNodeData>  $nodes
     */
    public function __construct(
        public int $id,
        /** The Gateway release ID (12 hex digits) whose verified record started the rollout, or null. */
        public ?string $release,
        public string $commit,
        /** `waiting`, `running`, `completed`, `halted`, or `superseded`. */
        public string $status,
        public ?string $haltedNode,
        public ?string $errorCode,
        public ?string $message,
        /** The `rollout_halted` alert receipt, or null. */
        public ?array $alert,
        /** The desired fleet state: commit, CLI release, agent pin, and the expected footprint digest per Node. */
        public array $desiredState,
        public array $nodes,
        public ?string $startedAt,
        public ?string $finishedAt,
    ) {}

    public static function fromModel(FleetRollout $rollout): self
    {
        $rollout->loadMissing(['release', 'haltedNode', 'nodes']);

        return new self(
            id: $rollout->id,
            release: $rollout->release?->release_id,
            commit: $rollout->commit,
            status: $rollout->status->value,
            haltedNode: $rollout->haltedNode?->name,
            errorCode: $rollout->error_code,
            message: $rollout->message,
            alert: $rollout->alert,
            desiredState: $rollout->desired_state,
            nodes: array_values($rollout->nodes->map(static fn (FleetRolloutNode $row): FleetRolloutNodeData => FleetRolloutNodeData::fromModel($row))->all()),
            startedAt: $rollout->started_at?->toIso8601String(),
            finishedAt: $rollout->finished_at?->toIso8601String(),
        );
    }
}
