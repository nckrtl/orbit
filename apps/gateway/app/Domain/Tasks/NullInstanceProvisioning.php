<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\AppInstance;

final readonly class NullInstanceProvisioning implements InstanceProvisioning
{
    public function provision(InstanceProvisionIntent $intent): ?AppInstance
    {
        return null;
    }
}
