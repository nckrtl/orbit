<?php

declare(strict_types=1);

namespace App\Domain\AppInstances;

use App\Models\AppInstance;

interface DevelopmentAppInstanceProvisioner
{
    public function reserve(AppInstance $appInstance, ?string $hostname): void;

    public function complete(AppInstance $appInstance, ?string $hostname): AppInstance;
}
