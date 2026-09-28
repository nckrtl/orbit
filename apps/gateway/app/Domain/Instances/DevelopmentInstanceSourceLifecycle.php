<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Models\Instance;

interface DevelopmentInstanceSourceLifecycle
{
    public function prepare(Instance $instance, bool $allowExisting): void;

    public function inspectPrepared(Instance $instance): void;

    public function resolve(Instance $instance): DevelopmentSourceResolution;

    public function inspectResolved(Instance $instance): DevelopmentSourceResolution;
}
