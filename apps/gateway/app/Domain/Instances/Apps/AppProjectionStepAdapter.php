<?php

declare(strict_types=1);

namespace App\Domain\Instances\Apps;

use App\Models\InstanceAppProjectionStep;

interface AppProjectionStepAdapter
{
    /** Inspect owned artifacts and resume from the protected receipt. Never recapture before-state. */
    public function recover(InstanceAppProjectionStep $step): ?AppProjectionReceipt;

    /** Create the stable receipt before mutation. Called only for a newly committed intent. */
    public function mutate(InstanceAppProjectionStep $step): AppProjectionReceipt;
}
