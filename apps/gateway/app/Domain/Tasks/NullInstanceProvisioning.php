<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Models\Instance;

final readonly class NullInstanceProvisioning implements InstanceProvisioning
{
    public function provision(InstanceProvisionIntent $intent): ?Instance
    {
        return null;
    }
}
