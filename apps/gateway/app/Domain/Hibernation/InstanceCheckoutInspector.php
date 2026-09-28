<?php

declare(strict_types=1);

namespace App\Domain\Hibernation;

use App\Models\Instance;

interface InstanceCheckoutInspector
{
    public function inspect(Instance $instance): RuntimeDependencyState;

    public function prune(Instance $instance, RuntimeDependencyState $state): void;

    public function restore(Instance $instance, RuntimeDependencyState $state): void;
}
