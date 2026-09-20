<?php

declare(strict_types=1);

namespace App\Domain\Apps;

use App\Models\AppInstance;

final readonly class AppDefaultBranchInheritance
{
    public function inheritsAppDefault(AppInstance $instance): bool
    {
        return $instance->placedOnAppDev()
            && $instance->name === 'default'
            && $instance->branch_override === null;
    }
}
