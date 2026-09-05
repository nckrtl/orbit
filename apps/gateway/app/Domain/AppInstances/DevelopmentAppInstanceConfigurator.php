<?php

declare(strict_types=1);

namespace App\Domain\AppInstances;

use App\Models\AppInstance;

interface DevelopmentAppInstanceConfigurator
{
    public function inspect(AppInstance $appInstance): DevelopmentSourceProfile;

    public function configureLaravelUrl(AppInstance $appInstance, string $url): void;
}
