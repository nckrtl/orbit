<?php

declare(strict_types=1);

namespace App\Domain\AppInstances;

use App\Models\AppInstance;

interface DevelopmentAppInstanceSourceLifecycle
{
    public function prepare(AppInstance $appInstance, bool $allowExisting): void;

    public function inspectPrepared(AppInstance $appInstance): void;

    public function resolve(AppInstance $appInstance): DevelopmentSourceResolution;

    public function inspectResolved(AppInstance $appInstance): DevelopmentSourceResolution;

    public function remove(AppInstance $appInstance, bool $discardSource): void;
}
