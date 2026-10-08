<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Models\Instance;

/** Eligibility only: remote removal still has to verify the attempt's ownership receipt. */
final readonly class InstanceCreationRecovery
{
    public static function isPreActivation(Instance $instance, bool $removing = false): bool
    {
        $states = [InstanceState::Reserved, InstanceState::CheckoutPrepared, InstanceState::SourceResolved];
        if ($removing) {
            $states[] = InstanceState::Removing;
        }

        return $instance->placedOnAppDev()
            && in_array($instance->status, $states, true)
            && ($instance->failed_step !== null && $instance->error_code !== null
                || ($instance->source_layout === InstanceSourceLayout::Checkout->value
                    && $instance->source_prepare_id !== null
                    // Legacy task preparation can persist the worktree layout without a preparation ID.
                    || $instance->source_layout === InstanceSourceLayout::Worktree->value
                    && ($instance->status === InstanceState::Reserved
                        || $removing && $instance->status === InstanceState::Removing)
                    && $instance->starting_commit === null
                    && $instance->task_workspace_routed !== null)
                && $instance->registration_request_id === null
                // A resolved unrouted task workspace is healthy, not an interrupted create.
                && ($instance->task_workspace_routed !== false || $instance->starting_commit === null));
    }
}
