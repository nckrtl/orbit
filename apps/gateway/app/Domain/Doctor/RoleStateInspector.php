<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

use App\Models\NodeRole;

interface RoleStateInspector
{
    /** @throws DoctorInspectionException */
    public function inspect(NodeRole $role): RoleInspectionData;
}
