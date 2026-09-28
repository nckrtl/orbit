<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Models\Instance;

interface DevelopmentInstanceConfigurator
{
    public function inspect(Instance $instance): DevelopmentSourceProfile;

    public function configureLaravelUrl(Instance $instance, string $url): void;
}
