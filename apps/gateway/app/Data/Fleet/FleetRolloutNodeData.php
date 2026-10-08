<?php

declare(strict_types=1);

namespace App\Data\Fleet;

use App\Models\FleetRolloutNode;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

/** One Node of a fleet rollout, in rollout order. */
#[MapOutputName(SnakeCaseMapper::class)]
final class FleetRolloutNodeData extends Data
{
    /** @param array<string, mixed>|null $evidence */
    public function __construct(
        public ?int $nodeId,
        public string $node,
        public int $position,
        /** `pending`, `converged`, `unchanged`, `unreachable`, `deferred`, `waiting`, `failed`, or `skipped`. */
        public string $outcome,
        /** The step that stopped the Node: `ssh`, `node-lock`, `baseline`, `cli`, `self-update`, `footprint`, or `verify`; `skipped` at `cli` means a foreign CLI. */
        public ?string $step,
        public ?string $errorCode,
        public ?string $message,
        /** What each step reported: the CLI install, the self-update report, the footprint, and the verify. */
        public ?array $evidence,
        public ?string $cliVersion,
        public ?string $agentVersion,
        public ?string $footprintDigest,
        public int $attempts,
        public ?string $startedAt,
        public ?string $finishedAt,
    ) {}

    public static function fromModel(FleetRolloutNode $row): self
    {
        return new self(
            nodeId: $row->node_id,
            node: $row->node_name,
            position: $row->position,
            outcome: $row->outcome->value,
            step: $row->step,
            errorCode: $row->error_code,
            message: $row->message,
            evidence: $row->evidence,
            cliVersion: $row->cli_version,
            agentVersion: $row->agent_version,
            footprintDigest: $row->footprint_digest,
            attempts: $row->attempts,
            startedAt: $row->started_at?->toIso8601String(),
            finishedAt: $row->finished_at?->toIso8601String(),
        );
    }
}
