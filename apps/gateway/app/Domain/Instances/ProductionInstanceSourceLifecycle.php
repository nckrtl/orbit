<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Models\Instance;

interface ProductionInstanceSourceLifecycle
{
    public function prepareUser(Instance $instance): void;

    public function prepareSource(Instance $instance, bool $allowExisting): void;

    public function resolve(Instance $instance): DevelopmentSourceResolution;

    public function inspectProfile(Instance $instance): DevelopmentSourceProfile;

    public function prepareCaddyAccess(Instance $instance): void;
}
