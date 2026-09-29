<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Models\Instance;

interface DevelopmentInstanceProvisioner
{
    public function reserve(Instance $instance, ?string $domain): void;

    public function complete(
        Instance $instance,
        ?string $domain,
        bool $setupPending = false,
    ): Instance;
}
