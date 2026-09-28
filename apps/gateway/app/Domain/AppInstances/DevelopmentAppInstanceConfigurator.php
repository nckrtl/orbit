<?php

declare(strict_types=1);

namespace App\Domain\AppInstances;

use App\Models\Instance;

interface DevelopmentAppInstanceConfigurator
{
    public function inspect(Instance $appInstance): DevelopmentSourceProfile;

    public function configureLaravelUrl(Instance $appInstance, string $url): void;
}
