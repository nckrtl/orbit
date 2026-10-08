<?php

declare(strict_types=1);

namespace App\Actions\Fleet;

use App\Data\Fleet\FleetRolloutStatusData;
use App\Domain\Fleet\DesiredFleetState;
use App\Domain\Fleet\FleetConvergeUnits;
use App\Domain\Fleet\FleetNodeOutcome;
use App\Domain\Fleet\FleetRolloutPlanner;
use App\Domain\Fleet\FleetRolloutStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\FleetRollout;
use App\Models\FleetRolloutNode;
use Illuminate\Support\Facades\DB;

/**
 * Resumes the halted fleet rollout (ADR 0202). The failed Node is visited again first, unless
 * `--skip` names it; a skipped Node stays `skipped` in this rollout. When the Gateway moved to a newer
 * commit since the halt, the halted rollout is superseded and a rollout of the newest desired state
 * opens, with the skipped Node carried over. The rollout then runs in `orbit-fleet-converge.service`,
 * never in this request.
 */
final readonly class ResumeFleetRolloutAction
{
    public function __construct(
        private DesiredFleetState $desired,
        private FleetRolloutPlanner $planner,
        private FleetConvergeUnits $units,
        private ShowFleetRolloutAction $show,
    ) {}

    public function execute(?string $skip): FleetRolloutStatusData
    {
        $rollout = FleetRollout::query()->where('status', FleetRolloutStatus::Halted->value)->latest('id')->first();

        if (! $rollout instanceof FleetRollout) {
            throw new ResourceOperationException('fleet.rollout_not_halted', 'No fleet rollout is halted.', 409);
        }

        $skipped = $skip === null ? null : $this->row($rollout, $skip);
        $state = $this->desired->current();

        DB::transaction(function () use ($rollout, $skipped, $state): void {
            $skipped?->forceFill(['outcome' => FleetNodeOutcome::Skipped, 'finished_at' => now()])->save();

            FleetRolloutNode::query()
                ->where('fleet_rollout_id', $rollout->id)
                ->where('outcome', FleetNodeOutcome::Failed->value)
                ->update(['outcome' => FleetNodeOutcome::Pending->value, 'step' => null, 'error_code' => null, 'message' => null]);

            if ($state->commit !== null && $state->commit !== $rollout->commit) {
                $rollout->forceFill(['status' => FleetRolloutStatus::Superseded, 'finished_at' => now()])->save();
                $gate = $this->planner->gate($state->commit);

                if (in_array($gate['state'], ['verified', 'in_place'], true)) {
                    $this->planner->open($state, $gate['release'], $skipped instanceof FleetRolloutNode ? [$skipped->node_name] : []);
                }

                return;
            }

            $rollout->forceFill([
                'status' => FleetRolloutStatus::Running,
                'halted_node_id' => null,
                'error_code' => null,
                'message' => null,
                'finished_at' => null,
            ])->save();
        });

        $this->units->start();

        return $this->show->execute();
    }

    private function row(FleetRollout $rollout, string $node): FleetRolloutNode
    {
        $row = FleetRolloutNode::query()
            ->where('fleet_rollout_id', $rollout->id)
            ->where(static fn ($query) => ctype_digit($node)
                ? $query->where('node_id', (int) $node)->orWhere('node_name', $node)
                : $query->where('node_name', $node))
            ->first();

        if (! $row instanceof FleetRolloutNode) {
            throw new ResourceOperationException('fleet.node_not_in_rollout', "Node [{$node}] is not in the halted rollout.", 422, details: ['node' => $node]);
        }

        if ($row->outcome->isConverged()) {
            throw new ResourceOperationException('fleet.node_already_converged', "Node [{$node}] already runs the desired state.", 422, details: ['node' => $node]);
        }

        return $row;
    }
}
