<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

use App\Models\NodeRole;

interface GatewayVpnStateInspector
{
    /** @throws DoctorInspectionException */
    public function inspect(NodeRole $role): GatewayVpnInspectionData;
}
