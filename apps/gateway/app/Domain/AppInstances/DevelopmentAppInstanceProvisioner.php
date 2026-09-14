<?php

declare(strict_types=1);

namespace App\Domain\AppInstances;

use App\Models\AppInstance;

interface DevelopmentAppInstanceProvisioner
{
    public function reserve(AppInstance $appInstance, ?string $domain): void;

    public function complete(
        AppInstance $appInstance,
        ?string $domain,
        bool $recoverSourceProfile = false,
    ): AppInstance;
}
