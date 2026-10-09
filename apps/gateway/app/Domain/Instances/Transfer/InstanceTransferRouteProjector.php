<?php

namespace App\Domain\Instances\Transfer;

use App\Models\Instance;
use App\Models\InstanceTransfer;
use App\Models\Route;

interface InstanceTransferRouteProjector
{
    /**
     * Issues the destination workload and Router leaves of a Route with a web root after cutover. The
     * Instance's next Caddy build on the destination already renders every site of the Instance, so each
     * leaf those sites name must exist first.
     */
    public function prepareDestinationCertificates(Instance $instance, Route $route): void;

    public function retireSource(InstanceTransfer $transfer): void;
}
