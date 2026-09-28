<?php

declare(strict_types=1);

namespace App\Domain\AppInstances;

use App\Models\Instance;

interface ProductionAppInstanceSourceLifecycle
{
    public function prepareUser(Instance $appInstance): void;

    public function prepareSource(Instance $appInstance, bool $allowExisting): void;

    public function resolve(Instance $appInstance): DevelopmentSourceResolution;

    public function inspectProfile(Instance $appInstance): DevelopmentSourceProfile;

    public function prepareCaddyAccess(Instance $appInstance): void;
}
