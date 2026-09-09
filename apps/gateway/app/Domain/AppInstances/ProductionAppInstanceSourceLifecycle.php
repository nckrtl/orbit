<?php

declare(strict_types=1);

namespace App\Domain\AppInstances;

use App\Models\AppInstance;

interface ProductionAppInstanceSourceLifecycle
{
    public function prepareUser(AppInstance $appInstance): void;

    public function prepareSource(AppInstance $appInstance, bool $allowExisting): void;

    public function resolve(AppInstance $appInstance): DevelopmentSourceResolution;

    public function inspectProfile(AppInstance $appInstance): DevelopmentSourceProfile;

    public function prepareCaddyAccess(AppInstance $appInstance): void;
}
