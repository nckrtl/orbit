<?php

namespace App\Domain\Instances\Transfer;

use App\Models\InstanceTransfer;

interface InstanceTransferRouteProjector
{
    public function retireSource(InstanceTransfer $transfer): void;
}
