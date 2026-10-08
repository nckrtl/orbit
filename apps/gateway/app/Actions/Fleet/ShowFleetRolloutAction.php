<?php

declare(strict_types=1);

namespace App\Actions\Fleet;

use App\Data\Fleet\FleetRolloutData;
use App\Data\Fleet\FleetRolloutExclusionData;
use App\Data\Fleet\FleetRolloutStatusData;
use App\Domain\Fleet\DesiredFleetState;
use App\Domain\Fleet\FleetRolloutMembership;
use App\Models\FleetRollout;
use App\Models\Node;

final readonly class ShowFleetRolloutAction
{
    public function __construct(
        private FleetRolloutMembership $membership,
        private DesiredFleetState $desired,
        private bool $enabled,
    ) {}

    public function execute(): FleetRolloutStatusData
    {
        $rollout = FleetRollout::query()->latest('id')->first();
        $excluded = [];

        foreach (Node::query()->with('roles')->orderBy('id')->get() as $node) {
            $reason = $this->membership->exclusion($node);

            if ($reason !== null) {
                $excluded[] = new FleetRolloutExclusionData($node->id, $node->name, $reason);
            }
        }

        return new FleetRolloutStatusData(
            enabled: $this->enabled,
            status: $rollout?->status->value ?? 'none',
            desiredCommit: $this->desired->current()->commit,
            rollout: $rollout instanceof FleetRollout ? FleetRolloutData::fromModel($rollout) : null,
            excluded: $excluded,
        );
    }
}
