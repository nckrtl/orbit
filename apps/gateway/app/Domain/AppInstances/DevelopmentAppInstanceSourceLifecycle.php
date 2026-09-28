<?php

declare(strict_types=1);

namespace App\Domain\AppInstances;

use App\Models\Instance;

interface DevelopmentAppInstanceSourceLifecycle
{
    public function prepare(Instance $appInstance, bool $allowExisting): void;

    public function inspectPrepared(Instance $appInstance): void;

    public function resolve(Instance $appInstance): DevelopmentSourceResolution;

    public function inspectResolved(Instance $appInstance): DevelopmentSourceResolution;
}
