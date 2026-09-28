<?php

declare(strict_types=1);

namespace App\Domain\Apps;

use App\Models\Instance;

final readonly class AppDefaultBranchInheritance
{
    public function inheritsAppDefault(Instance $instance): bool
    {
        return $instance->placedOnAppDev()
            && $instance->name === 'default'
            && $instance->branch_override === null;
    }
}
