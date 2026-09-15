<?php

namespace App\Domain\AppInstances\Transfer;

use App\Models\AppInstanceTransfer;

interface AppInstanceTransferRouteProjector
{
    public function retireSource(AppInstanceTransfer $transfer): void;
}
